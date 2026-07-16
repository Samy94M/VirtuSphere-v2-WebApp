<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/db.php';

/**
 * Steuert die IP-Allowlist (deploy_accessToWebAPI) fuer Wire-Tests, statt vom
 * Umgebungszustand abhaengig zu skippen: in der Integration-Lane ist ein
 * dynamischer Skip nie legitim (ADR-0015-Ergaenzung), also stellt der Test den
 * Zustand her, den er behauptet zu pruefen.
 *
 * Die eigene Client-IP kommt aus gethostbyname(gethostname()): die Wire-Tests
 * laufen per Definition im Compose-Netz gegen webserver:8080 (Default in
 * tests/bootstrap.php), und genau diese Container-IP sieht der Endpoint als
 * REMOTE_ADDR. restoreClientIpAllowlist() stellt in tearDown den
 * Ausgangszustand exakt wieder her, damit ein Lauf gegen den Dev-Stack dessen
 * Allowlist nicht veraendert.
 */
trait ClientIpAllowlist
{
    /** @var list<string> IPs, die dieser Test eingetragen hat */
    private array $allowlistInserted = [];

    /** @var list<array{ip: string, description: string}> Zeilen, die dieser Test entfernt hat */
    private array $allowlistRemoved = [];

    private function ownClientIp(): string
    {
        $host = (string) gethostname();
        $ip = gethostbyname($host);
        self::assertNotSame($host, $ip, 'Eigene Container-IP nicht bestimmbar (gethostbyname).');

        return $ip;
    }

    private function ensureClientIpAllowlisted(mysqli $db): void
    {
        $ip = $this->ownClientIp();
        $count = 0;
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_accessToWebAPI WHERE ipAddress = ?');
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (is_array($row)) {
            $count = (int) $row['c'];
        }
        if ($count > 0) {
            return;
        }

        $stmt = $db->prepare("INSERT INTO deploy_accessToWebAPI (ipAddress, description) VALUES (?, 'phpunit wire temp')");
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $this->allowlistInserted[] = $ip;
    }

    private function ensureClientIpNotAllowlisted(mysqli $db): void
    {
        $ip = $this->ownClientIp();
        $stmt = $db->prepare('SELECT ipAddress, description FROM deploy_accessToWebAPI WHERE ipAddress = ?');
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $result = $stmt->get_result();
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $this->allowlistRemoved[] = [
                'ip' => (string) $row['ipAddress'],
                'description' => (string) $row['description'],
            ];
        }
        $result->free();

        $stmt = $db->prepare('DELETE FROM deploy_accessToWebAPI WHERE ipAddress = ?');
        $stmt->bind_param('s', $ip);
        $stmt->execute();
    }

    private function restoreClientIpAllowlistIfTouched(): void
    {
        if ($this->allowlistInserted === [] && $this->allowlistRemoved === []) {
            return;
        }
        $this->restoreClientIpAllowlist(db(true));
    }

    private function restoreClientIpAllowlist(mysqli $db): void
    {
        foreach ($this->allowlistInserted as $ip) {
            $stmt = $db->prepare("DELETE FROM deploy_accessToWebAPI WHERE ipAddress = ? AND description = 'phpunit wire temp'");
            $stmt->bind_param('s', $ip);
            $stmt->execute();
        }
        foreach ($this->allowlistRemoved as $row) {
            $stmt = $db->prepare('INSERT INTO deploy_accessToWebAPI (ipAddress, description) VALUES (?, ?)');
            $stmt->bind_param('ss', $row['ip'], $row['description']);
            $stmt->execute();
        }
        $this->allowlistInserted = [];
        $this->allowlistRemoved = [];
    }
}
