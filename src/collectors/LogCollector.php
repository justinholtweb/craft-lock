<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\helpers\FileHelper;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * Craft's log files, searched for the address.
 *
 * The awkward source, and the honest thing to do with it is say so. A log file is an append-only
 * record whose value is that it has not been edited; rewriting one line out of the middle of it to
 * satisfy an erasure request destroys the property that made it worth keeping, and leaves a file
 * that is now evidence of nothing.
 *
 * So logs are **disclosed but never rewritten**, with the reason attached, and the remedy Lock
 * offers is the correct one: a retention rule that deletes whole log files once they are older
 * than the period the site has decided on. Storage limitation, applied to logs, is the answer to
 * personal data in logs.
 */
class LogCollector extends BaseCollector
{
    /** Never read more than this from one file; a log can be hundreds of megabytes. */
    private const MAX_BYTES = 12_000_000;

    private const MAX_MATCHES_PER_FILE = 25;

    public static function handle(): string
    {
        return 'logs';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Log files');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Craft and plugin logs in storage/logs, searched line by line for the address.');
    }

    public function isAvailable(): bool
    {
        return $this->settings()->scanLogs && is_dir(Craft::$app->getPath()->getLogPath());
    }

    public function unavailableReason(): ?string
    {
        return $this->settings()->scanLogs
            ? Craft::t('lock', 'There is no log directory.')
            : Craft::t('lock', 'Log scanning is switched off in Lock’s settings.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $email = $subject->normalisedEmail();

        if ($email === '') {
            return [];
        }

        $records = [];
        $truncated = false;

        foreach ($this->logFiles() as $path) {
            $matches = [];
            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                $bundle->errors[] = Craft::t('lock', 'Could not read {file}.', ['file' => basename($path)]);
                continue;
            }

            $read = 0;

            while (($line = fgets($handle)) !== false) {
                $read += strlen($line);

                if ($read > self::MAX_BYTES) {
                    $truncated = true;
                    break;
                }

                if (stripos($line, $email) === false) {
                    continue;
                }

                $matches[] = rtrim($line);

                if (count($matches) >= self::MAX_MATCHES_PER_FILE) {
                    $truncated = true;
                    break;
                }
            }

            fclose($handle);

            if ($matches === []) {
                continue;
            }

            $record = $this->record('file:' . basename($path), Craft::t('lock', '{n} log lines in {file}', [
                'n' => count($matches),
                'file' => basename($path),
            ]), [
                'File' => basename($path),
                'Lines' => $matches,
            ]);
            $record->kind = DataRecord::KIND_LOG;
            $record->categories = [DataRecord::CATEGORY_TECHNICAL];
            $record->erasable = false;
            $record->anonymisable = false;
            $record->retainReason = Craft::t('lock', 'A log file is only useful because it has not been edited. Lock does not rewrite one; set a retention rule that deletes old log files instead.');
            $record->dateCreated = ($mtime = @filemtime($path)) !== false ? (new DateTime())->setTimestamp($mtime) : null;
            $records[] = $record;
        }

        if ($truncated) {
            $bundle->notes[] = Craft::t('lock', 'Log searching stopped early on at least one file — the disclosure shows the first matches, not necessarily every one.');
        }

        return $records;
    }

    /** @return string[] */
    private function logFiles(): array
    {
        $path = Craft::$app->getPath()->getLogPath();

        if (!is_dir($path)) {
            return [];
        }

        $files = FileHelper::findFiles($path, ['only' => ['*.log'], 'recursive' => false]);
        sort($files);

        return $files;
    }

    /**
     * Deletes a whole log file.
     *
     * Only ever reached from a retention rule. Every record a *subject request* produces here is
     * marked unerasable and carries a retain reason, so it can only ever be planned as a skip —
     * which is why this method can delete without needing to ask which of the two it is serving.
     */
    public function apply(ErasureTarget $target, Subject $subject): void
    {
        if ($target->action !== ErasureTarget::ACTION_ERASE) {
            return;
        }

        $body = $this->keyBody($target->key);

        if (!str_starts_with($body, 'file:')) {
            return;
        }

        // `basename` on the way in as well as here: the key travels through a stored plan, and a
        // stored plan is a place a path can be tampered with.
        $path = Craft::$app->getPath()->getLogPath() . DIRECTORY_SEPARATOR . basename(substr($body, 5));

        if (is_file($path)) {
            FileHelper::unlink($path);
        }
    }

    public function scopes(): array
    {
        $scope = new RetentionScope();
        $scope->key = 'logs:files';
        $scope->source = self::handle();
        $scope->label = Craft::t('lock', 'Log files');
        $scope->description = Craft::t('lock', 'Whole log files older than the period, deleted. This is the only lawful way to get personal data out of logs without destroying what they are for.');
        $scope->measuredFrom = Craft::t('lock', 'last written to');
        $scope->anonymisable = false;
        $scope->suggestedMonths = 3;

        return [$scope];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if ($scope->key !== 'logs:files') {
            return [];
        }

        $records = [];

        foreach ($this->logFiles() as $path) {
            $mtime = @filemtime($path);

            if ($mtime === false || $mtime >= $cutoff->getTimestamp()) {
                continue;
            }

            $record = $this->record('file:' . basename($path), Craft::t('lock', '{file}, last written {date}', [
                'file' => basename($path),
                'date' => date('Y-m-d', $mtime),
            ]), []);
            $record->kind = DataRecord::KIND_LOG;
            $record->anonymisable = false;
            $record->erasable = true;
            $record->retainReason = null;
            $records[] = $record;

            if (count($records) >= $limit) {
                break;
            }
        }

        return $records;
    }
}
