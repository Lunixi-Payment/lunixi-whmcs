<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\SecretBox;

/** Immutable encrypted credentials: creating a new profile never destroys historical access. */
final class Profiles
{
    public function __construct(private Connection $db, private SecretBox $box)
    {
    }
    /** @param array<string,mixed> $data */
    public function create(string $label, array $data): int
    {
        $label = trim($label);
        if ($label === '' || strlen($label) > 100) {
            throw new \InvalidArgumentException('A profile label is required.');
        }
        foreach (['merchantId', 'keyId', 'privateKey', 'webhookSecret'] as $name) {
            if (!isset($data[$name]) || !is_string($data[$name]) || trim($data[$name]) === '' || strlen($data[$name]) > 16384) {
                throw new \InvalidArgumentException('Profile credentials are incomplete.');
            }
        }
        if (!in_array($data['mode'] ?? null, ['hosted', 'embedded'], true) || !is_bool($data['preauth'] ?? null) || !in_array($data['environment'] ?? null, ['TEST', 'LIVE'], true)) {
            throw new \InvalidArgumentException('Invalid checkout configuration.');
        }
        // Deliberately no editable gateway URL, method switches or arbitrary scripts.
        $safe = array_intersect_key($data, array_flip(['merchantId', 'keyId', 'privateKey', 'webhookSecret', 'mode', 'preauth', 'environment']));
        return (int) $this->db->table('mod_lunixi_profiles')->insertGetId(['label' => $label, 'encrypted' => $this->box->encrypt(json_encode($safe, JSON_THROW_ON_ERROR))]);
    }
    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->db->table('mod_lunixi_profiles')->where('id', $id)->first();
        if ($row === null) {
            throw new \RuntimeException('The historical credential profile is unavailable.');
        }
        return json_decode($this->box->decrypt($row->encrypted), true, 512, JSON_THROW_ON_ERROR);
    }

    /** New same-merchant profiles rotate authentication, never historical amount or checkout configuration. */
    /** @return array<string,mixed> */
    public function credentials(int $id): array
    {
        $historical = $this->get($id);
        foreach ($this->listing() as $candidate) {
            $profile = $this->get((int) $candidate->id);
            if ($profile['merchantId'] === $historical['merchantId'] && $profile['environment'] === $historical['environment']) {
                return $profile;
            }
        }
        return $historical;
    }
    /** @return list<string> */
    public function verificationSecrets(int $id): array
    {
        $historical = $this->get($id);
        $secrets = [$historical['webhookSecret']];
        foreach ($this->listing() as $candidate) {
            $profile = $this->get((int) $candidate->id);
            if ($profile['merchantId'] === $historical['merchantId'] && $profile['environment'] === $historical['environment']) {
                $secrets[] = $profile['webhookSecret'];
            }
        }
        return array_values(array_unique($secrets));
    }
    /** @return array<int,\stdClass> */
    public function listing(): array
    {
        return $this->db->table('mod_lunixi_profiles')->orderBy('id', 'desc')->get(['id', 'label'])->all();
    }
}
