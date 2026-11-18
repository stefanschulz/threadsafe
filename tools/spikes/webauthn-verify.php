<?php

/**
 * Spike of phase 14, work package 0 (docs/PHASE14.md 5): can the answers of a real browser's WebAuthn be checked with plain PHP
 * (a small CBOR reader, openssl for ES256 and RS256, sodium for EdDSA)? Reads the file that tools/spikes/webauthn-virtual.mjs wrote and
 * checks, for every case: the client data (type, challenge, origin), the authenticator data (rpIdHash, flags, counter), the COSE key and
 * the signature of the assertion. Throw-away code: the real classes of the phase are written after this has shown that it works.
 *
 *   php tools/spikes/webauthn-verify.php .cache/webauthn-vectors.json
 */

declare(strict_types=1);

function b64u(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true) ?: throw new RuntimeException('bad base64url');
}

/** @return array{0: mixed, 1: int} the item and the offset after it */
function cbor(string $d, int $o = 0, int $depth = 0): array
{
    if ($depth > 8 || $o >= strlen($d)) {
        throw new RuntimeException('cbor: depth or end');
    }
    $ib = ord($d[$o++]);
    $major = $ib >> 5;
    $ai = $ib & 31;
    if ($ai < 24) {
        $n = $ai;
    } elseif ($ai === 24) {
        $n = ord($d[$o]);
        $o += 1;
    } elseif ($ai === 25) {
        $n = unpack('n', substr($d, $o, 2))[1];
        $o += 2;
    } elseif ($ai === 26) {
        $n = unpack('N', substr($d, $o, 4))[1];
        $o += 4;
    } else {
        throw new RuntimeException('cbor: length form ' . $ai);
    }
    switch ($major) {
        case 0:
            return [$n, $o];
        case 1:
            return [-1 - $n, $o];
        case 2:
        case 3:
            return [substr($d, $o, $n), $o + $n];
        case 4:
            $a = [];
            for ($i = 0; $i < $n; $i++) {
                [$a[], $o] = cbor($d, $o, $depth + 1);
            }
            return [$a, $o];
        case 5:
            $m = [];
            for ($i = 0; $i < $n; $i++) {
                [$k, $o] = cbor($d, $o, $depth + 1);
                [$v, $o] = cbor($d, $o, $depth + 1);
                $m[$k] = $v;
            }
            return [$m, $o];
        default:
            throw new RuntimeException('cbor: major ' . $major);
    }
}

function der(int $tag, string $body): string
{
    $l = strlen($body);
    $len = $l < 128 ? chr($l) : (($l < 256) ? "\x81" . chr($l) : "\x82" . pack('n', $l));
    return chr($tag) . $len . $body;
}

function derInt(string $be): string
{
    $be = ltrim($be, "\0");
    return der(0x02, ((ord($be[0]) & 0x80) ? "\0" : '') . $be);
}

/** @return array{alg: int, pem: ?string, raw: ?string} */
function coseKey(array $k): array
{
    $alg = $k[3];
    if ($alg === -7) {
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
        return ['alg' => $alg, 'pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n", 'raw' => null];
    }
    if ($alg === -257) {
        $rsa = der(0x30, derInt($k[-1]) . derInt($k[-2]));
        $spki = der(0x30, hex2bin('300d06092a864886f70d0101010500') . der(0x03, "\0" . $rsa));
        return ['alg' => $alg, 'pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n", 'raw' => null];
    }
    if ($alg === -8) {
        return ['alg' => $alg, 'pem' => null, 'raw' => $k[-2]];
    }
    throw new RuntimeException('algorithm ' . $alg);
}

$file = $argv[1] ?? '.cache/webauthn-vectors.json';
$data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
$rpIdHash = hash('sha256', $data['rpId'], true);
$failed = 0;

foreach ($data['cases'] as $case) {
    $label = json_encode($case['offered']);
    if (!$case['ok']) {
        echo "$label: the browser failed ({$case['error']})\n";
        $failed++;
        continue;
    }
    try {
        $reg = $case['answer']['registration'];
        $cd = json_decode(b64u($reg['clientDataJSON']), true, 8, JSON_THROW_ON_ERROR);
        assert($cd['type'] === 'webauthn.create' && $cd['challenge'] === $reg['challenge'] && $cd['origin'] === $case['answer']['origin']);
        [$att] = cbor(b64u($reg['attestationObject']));
        $ad = $att['authData'];
        assert(substr($ad, 0, 32) === $rpIdHash);
        $flags = ord($ad[32]);
        $up = (bool) ($flags & 1);
        $uv = (bool) ($flags & 4);
        $be = (bool) ($flags & 8);
        $bs = (bool) ($flags & 16);
        $at = (bool) ($flags & 64);
        $idLen = unpack('n', substr($ad, 53, 2))[1];
        $credId = substr($ad, 55, $idLen);
        assert($credId === b64u($reg['id']));
        [$cose] = cbor($ad, 55 + $idLen);
        $key = coseKey($cose);

        $as = $case['answer']['assertion'];
        $acd = b64u($as['clientDataJSON']);
        $cdj = json_decode($acd, true, 8, JSON_THROW_ON_ERROR);
        assert($cdj['type'] === 'webauthn.get' && $cdj['challenge'] === $as['challenge'] && $cdj['origin'] === $case['answer']['origin']);
        $aad = b64u($as['authenticatorData']);
        assert(substr($aad, 0, 32) === $rpIdHash);
        $aflags = ord($aad[32]);
        $counter = unpack('N', substr($aad, 33, 4))[1];
        $signed = $aad . hash('sha256', $acd, true);
        $sig = b64u($as['signature']);
        $ok = match ($key['alg']) {
            -7, -257 => openssl_verify($signed, $sig, $key['pem'], OPENSSL_ALGO_SHA256) === 1,
            -8 => sodium_crypto_sign_verify_detached($sig, $signed, $key['raw']),
        };
        printf(
            "%s: alg %d, signature %s | registration flags UP=%d UV=%d BE=%d BS=%d AT=%d | assertion UP=%d UV=%d counter=%d | userHandle %s | transports %s\n",
            $label, $key['alg'], $ok ? 'VALID' : 'INVALID', $up, $uv, $be, $bs, $at, $aflags & 1, ($aflags >> 2) & 1, $counter,
            $as['userHandle'] === $reg['userId'] ? 'matches' : 'DIFFERS', json_encode($reg['transports']),
        );
        $failed += $ok ? 0 : 1;
        // a tampered answer must fail
        $bad = $signed . 'x';
        $tampered = match ($key['alg']) {
            -7, -257 => openssl_verify($bad, $sig, $key['pem'], OPENSSL_ALGO_SHA256) === 1,
            -8 => sodium_crypto_sign_verify_detached($sig, $bad, $key['raw']),
        };
        echo '   tampered data: ' . ($tampered ? 'ACCEPTED (bad)' : 'refused') . "\n";
        $failed += $tampered ? 1 : 0;
    } catch (Throwable $e) {
        echo "$label: " . $e::class . ': ' . $e->getMessage() . "\n";
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
