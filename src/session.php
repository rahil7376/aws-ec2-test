<?php

declare(strict_types=1);

/**
 * Stores PHP sessions in the `sessions` table.
 *
 * Default PHP sessions are files on the local disk. Behind a load balancer
 * that file exists on only one instance, so the next request (login, cart)
 * looks logged-out unless stickiness pins the browser to that instance.
 * A row in MySQL is visible to every instance.
 *
 * The handler also takes a MySQL named lock for the session id so two
 * overlapping requests from the same browser cannot clobber the row.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $lockName = null;

    /** Session id that session_destroy() / regenerate already deleted. */
    private ?string $skipWriteId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetime
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->unlock();

        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->validId($id)) {
            return '';
        }

        $this->lock($id);

        $stmt = $this->pdo->prepare(
            'SELECT data, last_activity FROM sessions WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return '';
        }

        if ((int) $row['last_activity'] < time() - $this->lifetime) {
            $delete = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');
            $delete->execute([$id]);

            return '';
        }

        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->validId($id) || $this->skipWriteId === $id) {
            return true;
        }

        return $this->store($id, $data, true);
    }

    public function validateId(string $id): bool
    {
        if (!$this->validId($id)) {
            return false;
        }

        // Strict mode calls this before read(). Unknown ids are rejected,
        // so a guessed cookie cannot pick an empty session slot.
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM sessions WHERE id = ? AND last_activity >= ?'
        );
        $stmt->execute([$id, time() - $this->lifetime]);

        return (bool) $stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->validId($id) || $this->skipWriteId === $id) {
            return true;
        }

        // lazy_write calls this instead of write() when the payload did not change.
        // last_activity still has to move, or an active browser expires mid-lab.
        $stmt = $this->pdo->prepare('UPDATE sessions SET last_activity = ? WHERE id = ?');

        return $stmt->execute([time(), $id]);
    }

    public function destroy(string $id): bool
    {
        $this->skipWriteId = $id;

        if (!$this->validId($id)) {
            return true;
        }

        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < ?');
        $stmt->execute([time() - $max_lifetime]);

        return $stmt->rowCount();
    }

    private function store(string $id, string $data, bool $insertIfMissing): bool
    {
        $now = time();
        $update = $this->pdo->prepare('UPDATE sessions SET data = ?, last_activity = ? WHERE id = ?');
        $update->bindValue(1, $data, PDO::PARAM_LOB);
        $update->bindValue(2, $now, PDO::PARAM_INT);
        $update->bindValue(3, $id, PDO::PARAM_STR);
        $update->execute();

        // rowCount is the number of changed rows. A brand-new id changes nothing.
        // An existing row changes because last_activity moves forward.
        if ($update->rowCount() > 0 || !$insertIfMissing) {
            return true;
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)'
            );
            $insert->bindValue(1, $id, PDO::PARAM_STR);
            $insert->bindValue(2, $data, PDO::PARAM_LOB);
            $insert->bindValue(3, $now, PDO::PARAM_INT);

            return $insert->execute();
        } catch (PDOException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlState === '23000' || $driverCode === 1062) {
                $update->execute();

                return true;
            }

            throw $e;
        }
    }

    private function validId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 128;
    }

    private function lock(string $id): void
    {
        // GET_LOCK names are capped at 64 characters. A session id can be longer.
        $this->lockName = hash('sha256', 'stateless-session-' . $id);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 15)');
        $stmt->execute([$this->lockName]);
        $stmt->fetchColumn();
    }

    private function unlock(): void
    {
        if ($this->lockName === null) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$this->lockName]);
        $stmt->fetchColumn();
        $this->lockName = null;
    }
}
