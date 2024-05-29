<?php declare(strict_types=1);

declare(strict_types=1);

namespace TelegramApiServer\EventObservers;

use Amp\Redis\RedisClient;
use danog\MadelineProto\EventHandler\Message;
use Revolt\EventLoop;
use TelegramApiServer\Config;
use TelegramApiServer\Files;
use Throwable;

use function Amp\Redis\createRedisClient;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    public static array $instances = [];
    public static RedisClient|null $redisDb = null;
    private string $sessionName;
    private array $sessionSettings = [];
    /**
     * Get peer(s) where to report errors
     *
     * @return int|string|array
     */
    public function getReportPeers()
    {
        return Config::getInstance()->get('laravel.reported_peers')??[];
    }

    public function onStart()
    {
        $this->sessionName = Files::getSessionName($this->wrapper->getSession()->getSessionPath());
        $this->initRedisDb();
        $this->report('Session '.$this->sessionName . ' started with settings:'.PHP_EOL . json_encode($this->sessionSettings));
        EventLoop::repeat(60.0, function () {
            $this->initRedisDb();
        });

        if (empty(static::$instances[$this->sessionName])) {
            static::$instances[$this->sessionName] = true;
            warning("Event observer CONSTRUCTED: {$this->sessionName}");
        }
    }

    public function __destruct()
    {
        if (empty($this->sessionName)) {
            return;
        }
        unset(static::$instances[$this->sessionName]);
        warning("Event observer DESTRUCTED: {$this->sessionName}");
    }

    public function onAny($update)
    {
        if ($this->isAllowedUpdate($update)) {
            info("Received update from session: {$this->sessionName}");
            EventObserver::notify($update, $this->sessionName);
        }
    }

    public function isAllowedUpdate($update): bool
    {
        if (isset($update['message']['out']) && $update['message']['out']) {
            return false;
        }
        if (empty($this->sessionSettings)) {
            return true;
        }
        if (isset($this->sessionSettings['allowed_updates'])) {
            $updates = (array)json_decode($this->sessionSettings['allowed_updates'], true);
            if (empty($updates)) {
                return false;
            }
            if (!in_array(($update['_'] ?? '_'), $updates)) {
                return false;
            }
        }
        if (isset($this->sessionSettings['allowed_channels'])) {
            if (isset($update['message'])) {
                try {
                    $upd = $this->wrapUpdate($update);
                } catch (Throwable $exception) {
                    return false;
                }


                if ($upd instanceof Message\ChannelMessage || $upd instanceof Message\GroupMessage) {
                    $channels = (array)json_decode($this->sessionSettings['allowed_channels'], true);
                    if (empty($channels)) {
                        return false;
                    }
                    $channel = $upd->chatId . '_' . ($upd->topicId ?? 0);
                    if (!in_array($channel, $channels)) {
                        return false;
                    }
                }
            }


        }
        return true;
    }

    public function initRedisDb()
    {
        if (($redisUrl = Config::getInstance()->get('laravel.redis_url'))) {
            try {
                static::$redisDb ??= createRedisClient($redisUrl);
                if (!empty(static::$redisDb)) {
                    try {
                       $this->sessionSettings = EventHandler::$redisDb->getMap(
                            'session:settings:' . $this->sessionName
                        )->getAll();
                    } catch (Throwable $exception) {
                        $this->report($exception->getMessage());
                    }
                }
            } catch (Throwable $exception) {
                $this->report($exception->getMessage());
            }
        }
    }
}