<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/db.php';

/**
 * Steuert die IP-Allowlist (deploy_accessToWebAPI) fuer Wire-Tests, statt vom
 * Umgebungszustand abhaengig zu skippen: in der Integration-Lane ist ein
 * dynamischer Skip nie legitim (ADR-0015-Ergaenzung), also stellt der Test den
 * Zustand her, den er behauptet zu pruefen.
 *
 * Die eigene Client-IP ist die Adresse, die der Endpoint als REMOTE_ADDR sieht:
 * die eigene IPv4 im Subnetz des Ziels aus tests/bootstrap.php (Default
 * webserver:8080). Seit der Netztrennung (C-07) haengt ein Testcontainer an
 * zwei Netzen, und gethostbyname(gethostname()) liefert dann eine beliebige der
 * beiden Adressen; es bleibt nur der Rueckfall, wenn keine Schnittstelle im
 * Zielnetz liegt. restoreClientIpAllowlist() stellt in tearDown den
 * Ausgangszustand exakt wieder her, damit ein Lauf gegen den Dev-Stack dessen
 * Allowlist nicht veraendert.
 */
trait ClientIpAllowlist
{
    /** @var list<string> IPs, die dieser Test eingetragen hat */
    private array $allowlistInserted = [];

    /** @var list<array{ip: string, description: string, role: ?string}> Zeilen, die dieser Test entfernt hat (K20: samt Rolle) */
    private array $allowlistRemoved = [];

    private function ownClientIp(): string
    {
        $targetHost = parse_url(virtusphere_test_base_url(), PHP_URL_HOST);
        $targetIp = is_string($targetHost) && $targetHost !== '' ? gethostbyname($targetHost) : '';
        $target = filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($targetIp) : false;
        if ($target !== false) {
            $interfaces = net_get_interfaces();
            foreach (is_array($interfaces) ? $interfaces : [] as $interface) {
                $unicast = is_array($interface) && is_array($interface['unicast'] ?? null) ? $interface['unicast'] : [];
                foreach ($unicast as $address) {
                    $ip = is_array($address) && is_string($address['address'] ?? null) ? $address['address'] : '';
                    $mask = is_array($address) && is_string($address['netmask'] ?? null) ? $address['netmask'] : '';
                    $ipLong = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($ip) : false;
                    $maskLong = filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($mask) : false;
                    if ($ipLong !== false && $maskLong !== false && ($ipLong & $maskLong) === ($target & $maskLong)) {
                        return $ip;
                    }
                }
            }
        }

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
        // A test that allowlisted itself in setUp and then removes the entry
        // again must not record its own temporary row as a pre-existing one:
        // restore would put it back, and the leftover role-less entry made the
        // System status page show an extra warning for every later lane step
        // (V1, release 2026.10.1). ensureClientIpAllowlisted() inserts only when
        // no row for the IP exists, so such a row is never an original one.
        $ownTemporary = in_array($ip, $this->allowlistInserted, true);
        $this->allowlistInserted = array_values(array_diff($this->allowlistInserted, [$ip]));
        $stmt = $db->prepare('SELECT ipAddress, description, role FROM deploy_accessToWebAPI WHERE ipAddress = ?');
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $result = $stmt->get_result();
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            if ($ownTemporary && $row['description'] === 'phpunit wire temp') {
                continue;
            }
            $this->allowlistRemoved[] = [
                'ip' => (string) $row['ipAddress'],
                'description' => (string) $row['description'],
                'role' => $row['role'] === null ? null : (string) $row['role'],
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
            $stmt = $db->prepare('INSERT INTO deploy_accessToWebAPI (ipAddress, description, role) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $row['ip'], $row['description'], $row['role']);
            $stmt->execute();
        }
        $this->allowlistInserted = [];
        $this->allowlistRemoved = [];
    }
}
