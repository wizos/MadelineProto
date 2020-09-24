<?php
/**
 * API wrapper module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Ipc;

use Amp\Deferred;
use Amp\Ipc\IpcServer;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Promise;
use danog\Loop\SignalLoop;
use danog\MadelineProto\Ipc\Runner\ProcessRunner;
use danog\MadelineProto\Ipc\Runner\WebRunner;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Loop\InternalLoop;
use danog\MadelineProto\SessionPaths;
use danog\MadelineProto\Tools;

/**
 * IPC server.
 */
class Server extends SignalLoop
{
    use InternalLoop;
    /**
     * Shutdown server.
     */
    const SHUTDOWN = 0;
    /**
     * Boolean to shut down worker, if started.
     */
    private static bool $shutdown = false;
    /**
     * Deferred to shut down worker, if started.
     */
    private static ?Deferred $shutdownDeferred = null;
    /**
     * IPC server.
     */
    private IpcServer $server;
    /**
     * Set IPC path.
     *
     * @param string $path IPC path
     *
     * @return void
     */
    public function setIpcPath(string $path): void
    {
        self::$shutdownDeferred = new Deferred;
        $this->server = new IpcServer($path);
    }
    /**
     * Start IPC server in background.
     *
     * @param SessionPaths $session   Session path
     *
     * @return Promise
     */
    public static function startMe(SessionPaths $session): Promise
    {
        $id = Tools::randomInt();
        try {
            Logger::log("Starting IPC server $session (process)");
            ProcessRunner::start($session, $id);
            WebRunner::start($session, $id);
            return Tools::call(self::monitor($session, $id));
        } catch (\Throwable $e) {
            Logger::log($e);
        }
        try {
            Logger::log("Starting IPC server $session (web)");
            WebRunner::start($session, $id);
        } catch (\Throwable $e) {
            Logger::log($e);
        }
        return Tools::call(self::monitor($session, $id));
    }
    /**
     * Monitor session.
     *
     * @param SessionPaths $session
     * @param int          $id
     *
     * @return \Generator
     */
    private static function monitor(SessionPaths $session, int $id): \Generator
    {
        while (true) {
            $state = yield $session->getIpcState();
            if ($state && $state->getStartupId() === $id) {
                if ($e = $state->getException()) {
                    Logger::log("IPC server got exception $e");
                    return $e;
                }
                Logger::log("IPC server started successfully!");
                return true;
            }
            yield Tools::sleep(1);
        }
        return false;
    }
    /**
     * Wait for shutdown.
     *
     * @return Promise
     */
    public static function waitShutdown(): Promise
    {
        return self::$shutdownDeferred->promise();
    }
    /**
     * Main loop.
     *
     * @return \Generator
     */
    public function loop(): \Generator
    {
        while ($socket = yield $this->waitSignal($this->server->accept())) {
            Tools::callFork($this->clientLoop($socket));
        }
        $this->server->close();
    }
    /**
     * Client handler loop.
     *
     * @param ChannelledSocket $socket Client
     *
     * @return \Generator
     */
    private function clientLoop(ChannelledSocket $socket): \Generator
    {
        $this->API->logger("Accepted IPC client connection!");

        $id = 0;
        $payload = null;
        try {
            while ($payload = yield $socket->receive()) {
                Tools::callFork($this->clientRequest($socket, $id++, $payload));
            }
        } finally {
            yield $socket->disconnect();
            if ($payload === self::SHUTDOWN) {
                $this->signal(null);
                if (self::$shutdownDeferred) {
                    self::$shutdownDeferred->resolve();
                }
            }
        }
    }
    /**
     * Handle client request.
     *
     * @param ChannelledSocket $socket  Socket
     * @param integer          $id      Request ID
     * @param array            $payload Payload
     *
     * @return \Generator
     */
    public function clientRequest(ChannelledSocket $socket, int $id, $payload): \Generator
    {
        try {
            $result = $this->API->{$payload[0]}(...$payload[1]);
            $result = $result instanceof \Generator ? yield from $result : yield $result;
        } catch (\Throwable $e) {
            $result = new ExitFailure($e);
        }
        try {
            yield $socket->send([$id, $result]);
        } catch (\Throwable $e) {
            $this->API->logger("Got error while trying to send result of ${payload[0]}: $e", Logger::ERROR);
            try {
                yield $socket->send([$id, new ExitFailure($e)]);
            } catch (\Throwable $e) {
                $this->API->logger("Got error while trying to send error of error of ${payload[0]}: $e", Logger::ERROR);
            }
        }
    }
    /**
     * Get the name of the loop.
     *
     * @return string
     */
    public function __toString(): string
    {
        return "IPC server";
    }
}