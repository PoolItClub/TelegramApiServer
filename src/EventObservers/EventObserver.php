<?php

namespace TelegramApiServer\EventObservers;


use danog\MadelineProto\APIWrapper;
use JsonException;
use ReflectionProperty;
use TelegramApiServer\Client;
use TelegramApiServer\Logger;
use Throwable;

class EventObserver
{
    use ObserverTrait;

    /** @var int[] */
    public static array $sessionClients = [];

    /**
     * @throws JsonException
     */
    public static function notify(array $update, string $sessionName): void
    {
        if (!empty(EventHandler::$redisDb)) {
            $update['subs'] = count(static::$subscribers);
            if ($update['subs'] == 0) {
                if (isset($update['message']['peer_id'])) {
                    try {
                        EventHandler::$redisDb->getList('missed_updates_' . $sessionName)->pushTail(
                            json_encode($update)
                        );
                    } catch (Throwable $exception) {
                        error("Redis: {$exception->getMessage()}");
                    }
                }
            }
        }


        foreach (static::$subscribers as $clientId => $callback) {
            notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $sessionName);
        }
    }

    private static function addSessionClient(string $session): void
    {
        if (empty(static::$sessionClients[$session])) {
            static::$sessionClients[$session] = 0;
        }
        ++static::$sessionClients[$session];
    }

    private static function removeSessionClient(string $session): void
    {
        if (!empty(static::$sessionClients[$session])) {
            --static::$sessionClients[$session];
        }
    }

    public static function startEventHandler(?string $requestedSession = null): void
    {
        $sessions = [];
        if ($requestedSession === null) {
            $sessions = array_keys(Client::getInstance()->instances);
        } else {
            $sessions[] = $requestedSession;
        }

        foreach ($sessions as $session) {
            static::addSessionClient($session);
            if (static::$sessionClients[$session] === 1) {
                warning("Start EventHandler: {$session}");
                try {
                    $instance = Client::getInstance()->getSession($session);
                    $property = new ReflectionProperty($instance, "wrapper");
                    /** @var APIWrapper $wrapper */
                    $wrapper = $property->getValue($instance);
                    EventHandler::cachePlugins(EventHandler::class);
                    $wrapper->getAPI()->setEventHandler(EventHandler::class);
                } catch (Throwable $e) {
                    static::removeSessionClient($session);
                    error('Cant set EventHandler', [
                        'session' => $session,
                        'exception' => Logger::getExceptionAsArray($e),
                    ]);
                }
            }
        }
    }

    public static function stopEventHandler(?string $requestedSession = null, bool $force = false): void
    {
        $sessions = [];
        if ($requestedSession === null) {
            $sessions = array_keys(Client::getInstance()->instances);
        } else {
            $sessions[] = $requestedSession;
        }
        foreach ($sessions as $session) {
            static::removeSessionClient($session);
            if (empty(static::$sessionClients[$session]) || $force) {
                warning("Stopping EventHandler: {$session}");
                Client::getInstance()->instances[$session]->unsetEventHandler();
                unset(EventHandler::$instances[$session], static::$sessionClients[$session]);
            }
        }

    }

}