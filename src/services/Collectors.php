<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use justinholtweb\lock\collectors\AddressCollector;
use justinholtweb\lock\collectors\AssetCollector;
use justinholtweb\lock\collectors\CollectorInterface;
use justinholtweb\lock\collectors\CommentsCollector;
use justinholtweb\lock\collectors\CommerceCollector;
use justinholtweb\lock\collectors\ConsentCollector;
use justinholtweb\lock\collectors\CustomTableCollector;
use justinholtweb\lock\collectors\EntryCollector;
use justinholtweb\lock\collectors\FormieCollector;
use justinholtweb\lock\collectors\FreeformCollector;
use justinholtweb\lock\collectors\LogCollector;
use justinholtweb\lock\collectors\RequestCollector;
use justinholtweb\lock\collectors\SessionCollector;
use justinholtweb\lock\collectors\TossCollector;
use justinholtweb\lock\collectors\UserCollector;
use justinholtweb\lock\events\RegisterCollectorsEvent;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use yii\base\InvalidConfigException;

/**
 * The register of places personal data lives.
 *
 * Order matters and is not alphabetical. The account comes first because everything else keys off
 * it, and the sources that can only ever be read — logs, Lock's own ledgers — come last, so that
 * a disclosure reads from "who you are" towards "traces you left" rather than the other way up.
 */
class Collectors extends Component
{
    public const EVENT_REGISTER_COLLECTORS = 'registerCollectors';

    /** @var CollectorInterface[]|null */
    private ?array $_collectors = null;

    /** @return class-string<CollectorInterface>[] */
    public static function defaultCollectors(): array
    {
        return [
            UserCollector::class,
            AddressCollector::class,
            CommerceCollector::class,
            FormieCollector::class,
            FreeformCollector::class,
            CommentsCollector::class,
            EntryCollector::class,
            AssetCollector::class,
            SessionCollector::class,
            CustomTableCollector::class,
            TossCollector::class,
            ConsentCollector::class,
            RequestCollector::class,
            LogCollector::class,
        ];
    }

    /**
     * Every registered collector, whether or not it is switched on or available.
     *
     * @return CollectorInterface[] keyed by handle
     */
    public function all(): array
    {
        if ($this->_collectors !== null) {
            return $this->_collectors;
        }

        $event = new RegisterCollectorsEvent(['collectors' => self::defaultCollectors()]);
        $this->trigger(self::EVENT_REGISTER_COLLECTORS, $event);

        $collectors = [];

        foreach ($event->collectors as $collector) {
            if (is_string($collector)) {
                if (!class_exists($collector) || !is_subclass_of($collector, CollectorInterface::class)) {
                    Craft::warning("Ignoring collector $collector: not a CollectorInterface.", Plugin::LOG_CATEGORY);
                    continue;
                }

                $collector = new $collector();
            }

            if (!$collector instanceof CollectorInterface) {
                continue;
            }

            $collectors[$collector::handle()] = $collector;
        }

        return $this->_collectors = $collectors;
    }

    /**
     * The ones that will actually run: registered, not switched off, and present on this site.
     *
     * @return CollectorInterface[]
     */
    public function enabled(): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return array_filter(
            $this->all(),
            static fn(CollectorInterface $c) => !in_array($c::handle(), $settings->disabledCollectors, true),
        );
    }

    public function get(string $handle): ?CollectorInterface
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * @throws InvalidConfigException if a stored plan names a collector that has since gone away —
     *         which has to be loud, because silently skipping it would report an erasure that did
     *         not happen.
     */
    public function require(string $handle): CollectorInterface
    {
        $collector = $this->get($handle);

        if ($collector === null) {
            throw new InvalidConfigException("No collector is registered for “{$handle}”. It may have been removed since this plan was made.");
        }

        return $collector;
    }

    /**
     * Every retention scope on the site, keyed by scope key.
     *
     * @return RetentionScope[]
     */
    public function scopes(): array
    {
        $scopes = [];

        foreach ($this->enabled() as $collector) {
            if (!$collector->isAvailable()) {
                continue;
            }

            foreach ($collector->scopes() as $scope) {
                $scopes[$scope->key] = $scope;
            }
        }

        return $scopes;
    }

    public function scope(string $key): ?RetentionScope
    {
        return $this->scopes()[$key] ?? null;
    }

    /** Forgets the cached list. Only needed when settings change inside one request. */
    public function reset(): void
    {
        $this->_collectors = null;
    }
}
