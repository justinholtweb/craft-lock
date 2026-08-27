<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\Dossier;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\base\Exception;
use ZipArchive;

/**
 * Assembling "everything we hold on this person", and turning it into something you can send.
 *
 * Assembly is a fan-out over the collectors and nothing more clever than that. The care is all in
 * what happens when one of them fails: the bundle keeps the error, the dossier reports itself as
 * partial, and the covering note in the export says so in words. An access request answered from
 * nine sources out of ten with no mention of the tenth is the failure that turns a handled request
 * into a complaint, and it is invisible unless the tool goes out of its way to make it visible.
 */
class Dossiers extends Component
{
    /**
     * Runs every enabled collector against one person.
     *
     * The assembly itself is logged to the ledger: reading everything a site holds about somebody
     * is itself an act of processing, and one that ought to leave a trace.
     */
    public function assemble(Subject $subject, ?int $requestId = null): Dossier
    {
        $subject->resolve();

        $dossier = new Dossier($subject);
        $dossier->requestId = $requestId;
        $started = microtime(true);

        foreach (Plugin::getInstance()->collectors->enabled() as $collector) {
            $dossier->bundles[$collector::handle()] = $collector->collect($subject);
        }

        $dossier->duration = round(microtime(true) - $started, 3);
        $dossier->builtAt = new DateTime();

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ACCESS,
            'dossier.assembled',
            Craft::t('lock', '{n} records found across {sources} sources.', [
                'n' => $dossier->count(),
                'sources' => count($dossier->populatedBundles()),
            ]),
            $subject,
            $requestId,
            ['partial' => $dossier->isPartial(), 'problems' => $dossier->problems()],
        );

        return $dossier;
    }

    /**
     * Writes the dossier out as a ZIP and hands back its path, relative to the plugin's storage.
     *
     * Four formats, all built from `Dossier::toDisclosure()`. That is the point: JSON for a
     * portability request, CSV for a spreadsheet, HTML to read and print, and a covering note that
     * explains what was searched. Rendering them from separate walks of the data would eventually
     * let the three describe different holdings.
     *
     * @throws Exception if the archive cannot be written
     */
    public function export(Dossier $dossier, ?string $password = null): string
    {
        $disclosure = $dossier->toDisclosure();
        $slug = StringHelper::slugify($dossier->subject->normalisedEmail()) ?: 'subject';
        $stamp = ($dossier->builtAt ?? new DateTime())->format('Ymd-His');
        $name = "lock-$slug-$stamp.zip";

        $directory = $this->storagePath();
        FileHelper::createDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $name;

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Could not create the dossier archive at $path.");
        }

        if ($password !== null && $password !== '') {
            // AES-256 rather than the legacy ZipCrypto, which is breakable with a few known
            // plaintext bytes — and a ZIP of somebody's whole life is not the place for it.
            $zip->setPassword($password);
        }

        $files = [
            'README.txt' => $this->covering($dossier),
            'data.json' => Json::encode($disclosure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'data.csv' => $this->csv($dossier),
            'data.html' => $this->html($dossier),
        ];

        foreach ($files as $filename => $contents) {
            $zip->addFromString($filename, $contents);

            if ($password !== null && $password !== '') {
                $zip->setEncryptionName($filename, ZipArchive::EM_AES_256);
            }
        }

        $zip->close();

        return $name;
    }

    /** Where built dossiers live: outside the web root, under Craft's storage folder. */
    public function storagePath(): string
    {
        return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'lock' . DIRECTORY_SEPARATOR . 'dossiers';
    }

    public function pathFor(string $filename): string
    {
        return $this->storagePath() . DIRECTORY_SEPARATOR . basename($filename);
    }

    /** A one-use password, shown to the operator once and never stored. */
    public function generatePassword(): string
    {
        return StringHelper::randomString(20);
    }

    /**
     * Deletes dossiers older than the configured window. Returns how many went.
     *
     * A built dossier is the most concentrated personal data on the whole site — one file with
     * everything about one person in it. Leaving those lying around in storage indefinitely would
     * make Lock the biggest data-protection problem on the site it was installed to fix.
     */
    public function prune(?int $days = null): int
    {
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $days ??= $settings->dossierTtl;

        if ($days <= 0 || !is_dir($this->storagePath())) {
            return 0;
        }

        $cutoff = time() - ($days * 86400);
        $removed = 0;

        foreach (FileHelper::findFiles($this->storagePath(), ['only' => ['*.zip'], 'recursive' => false]) as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < $cutoff) {
                FileHelper::unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function covering(Dossier $dossier): string
    {
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $lines = [
            'YOUR PERSONAL DATA',
            str_repeat('=', 18),
            '',
            'Prepared by: ' . ($settings->organisationName ?: Craft::$app->getSystemName()),
            'Prepared on: ' . ($dossier->builtAt?->format('j F Y') ?? ''),
            'About: ' . $dossier->subject->label(),
            'Records found: ' . $dossier->count(),
            '',
            'This archive contains the same information three times over, so that you can',
            'read it, open it in a spreadsheet, or load it into another service:',
            '',
            '  data.html  — the readable version. Open it in a browser.',
            '  data.csv   — one row per record, for a spreadsheet.',
            '  data.json  — the machine-readable version, for moving it somewhere else.',
            '',
            'WHERE WE LOOKED',
            str_repeat('-', 15),
            '',
        ];

        foreach ($dossier->bundles as $bundle) {
            $lines[] = $bundle->searched
                ? sprintf('  %-42s %s', $bundle->sourceLabel, $bundle->count() === 0 ? 'nothing held' : $bundle->count() . ' record(s)')
                : sprintf('  %-42s not searched — %s', $bundle->sourceLabel, $bundle->skipReason);
        }

        if ($dossier->isPartial()) {
            $lines[] = '';
            $lines[] = 'PLEASE NOTE';
            $lines[] = str_repeat('-', 11);
            $lines[] = '';
            $lines[] = 'Some sources could not be searched completely. This copy may therefore be';
            $lines[] = 'incomplete, and we would rather tell you than not:';
            $lines[] = '';

            foreach ($dossier->problems() as $problem) {
                $lines[] = '  - ' . $problem;
            }
        }

        $retained = $dossier->retained();

        if ($retained !== []) {
            $lines[] = '';
            $lines[] = 'THINGS WE HAVE TO KEEP';
            $lines[] = str_repeat('-', 22);
            $lines[] = '';
            $lines[] = 'Some of the records below cannot be deleted even if you ask us to, because';
            $lines[] = 'we are required to keep them. Where that applies, here is what and why:';
            $lines[] = '';

            foreach ($retained as $record) {
                $lines[] = sprintf('  - %s: %s', $record->label, $record->retainReason);
            }
        }

        $lines[] = '';
        $lines[] = 'If anything here is wrong, or you want it corrected or deleted, write to';
        $lines[] = $settings->resolvedContactEmail() . '.';

        if ($settings->policyUrl !== '') {
            $lines[] = 'Our privacy policy is at ' . $settings->policyUrl . '.';
        }

        return implode("\n", $lines) . "\n";
    }

    private function csv(Dossier $dossier): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Source', 'Record', 'Held since', 'Field', 'Value']);

        foreach ($dossier->bundles as $bundle) {
            foreach ($bundle->records as $record) {
                foreach ($this->flatData($record) as $field => $value) {
                    fputcsv($handle, [
                        $bundle->sourceLabel,
                        $record->label,
                        $record->dateCreated?->format('Y-m-d') ?? '',
                        $field,
                        is_scalar($value) ? (string)$value : Json::encode($value),
                    ]);
                }
            }
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @return array<string, mixed> */
    private function flatData(DataRecord $record): array
    {
        $flat = [];

        foreach ($record->data as $key => $value) {
            if (is_array($value)) {
                foreach (\justinholtweb\lock\helpers\Readable::flatten($value, (string)$key) as $k => $v) {
                    $flat[$k] = $v;
                }

                continue;
            }

            $flat[(string)$key] = $value;
        }

        return $flat;
    }

    private function html(Dossier $dossier): string
    {
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $html = [];
        $html[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html[] = '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html[] = '<title>' . $e(Craft::t('lock', 'Your personal data')) . '</title>';
        $html[] = '<style>'
            . 'body{font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1.25rem;color:#1c2024;background:#fff}'
            . 'h1{font-size:1.6rem;margin-bottom:.25rem}h2{font-size:1.15rem;margin-top:2.5rem;border-bottom:1px solid #dfe3e8;padding-bottom:.35rem}'
            . 'h3{font-size:1rem;margin:1.5rem 0 .35rem}table{border-collapse:collapse;width:100%;margin:.5rem 0 1rem}'
            . 'th,td{text-align:left;vertical-align:top;padding:.4rem .6rem;border-bottom:1px solid #eceff2;font-size:.94rem}'
            . 'th{width:15rem;font-weight:600;color:#495057}.note{background:#fdf6e3;border-left:3px solid #c9a227;padding:.8rem 1rem;margin:1rem 0}'
            . '.muted{color:#69717a}@media (prefers-color-scheme:dark){body{background:#16181a;color:#e6e8ea}th{color:#9aa2ab}'
            . 'th,td{border-bottom-color:#2a2f36}h2{border-bottom-color:#2a2f36}.note{background:#241f10}}'
            . '</style></head><body>';

        $html[] = '<h1>' . $e(Craft::t('lock', 'Your personal data')) . '</h1>';
        $html[] = '<p class="muted">' . $e(Craft::t('lock', 'Held by {org}, prepared on {date} for {subject}.', [
            'org' => $settings->organisationName ?: Craft::$app->getSystemName(),
            'date' => $dossier->builtAt?->format('j F Y') ?? '',
            'subject' => $dossier->subject->label(),
        ])) . '</p>';

        if ($dossier->isPartial()) {
            $html[] = '<div class="note"><strong>' . $e(Craft::t('lock', 'This copy may be incomplete.')) . '</strong><ul>';

            foreach ($dossier->problems() as $problem) {
                $html[] = '<li>' . $e($problem) . '</li>';
            }

            $html[] = '</ul></div>';
        }

        foreach ($dossier->bundles as $bundle) {
            if ($bundle->isEmpty()) {
                continue;
            }

            $html[] = '<h2>' . $e($bundle->sourceLabel) . '</h2>';

            foreach ($bundle->notes as $note) {
                $html[] = '<p class="muted">' . $e($note) . '</p>';
            }

            foreach ($bundle->records as $record) {
                $html[] = '<h3>' . $e($record->label) . '</h3>';

                if ($record->retainReason !== null) {
                    $html[] = '<p class="note">' . $e($record->retainReason) . '</p>';
                }

                $html[] = '<table>';

                foreach ($this->flatData($record) as $field => $value) {
                    $html[] = '<tr><th>' . $e($field) . '</th><td>'
                        . nl2br($e(is_scalar($value) ? (string)$value : Json::encode($value)))
                        . '</td></tr>';
                }

                $html[] = '</table>';
            }
        }

        $html[] = '<h2>' . $e(Craft::t('lock', 'Where we looked')) . '</h2><table>';

        foreach ($dossier->bundles as $bundle) {
            $html[] = '<tr><th>' . $e($bundle->sourceLabel) . '</th><td>' . $e($bundle->searched
                ? ($bundle->count() === 0 ? Craft::t('lock', 'nothing held') : Craft::t('lock', '{n} records', ['n' => $bundle->count()]))
                : Craft::t('lock', 'not searched — {reason}', ['reason' => $bundle->skipReason])) . '</td></tr>';
        }

        $html[] = '</table></body></html>';

        return implode("\n", $html);
    }
}
