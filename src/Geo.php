<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Country lookup. Uses Cloudflare's CF-IPCountry header when the install sits behind
 * Cloudflare, otherwise an optional local database (bin/wyn-analytics geo:update).
 * Only the two-letter country code is kept. No city, no coordinates, no IP.
 *
 * Database file format (built from DB-IP's free "IP to Country Lite" CSV, CC BY 4.0):
 *   "WYNGEO1\n", uint32 v4 count, uint32 v6 count, uint16 label length, label,
 *   v4 records (4-byte start + 2-byte country), v6 records (16-byte start + 2-byte country).
 * Records are sorted; each range runs until the next record starts. "--" marks a gap.
 */
final class Geo
{
    public const SOURCE_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';
    private const MAGIC = "WYNGEO1\n";

    /** @var resource|null */
    private $fh = null;
    private int $v4Count = 0;
    private int $v6Count = 0;
    private int $v4Offset = 0;
    private int $v6Offset = 0;
    private string $label = '';
    private bool $opened = false;

    public function __construct(private readonly string $path)
    {
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function available(): bool
    {
        return $this->open();
    }

    public function label(): string
    {
        $this->open();

        return $this->label;
    }

    /**
     * @param ?string $cfCountry CF-IPCountry header, only passed when the proxy is trusted
     */
    public function country(string $ip, ?string $cfCountry = null): string
    {
        if ($cfCountry !== null) {
            $cc = strtoupper(trim($cfCountry));
            // XX = unknown, T1 = Tor at Cloudflare.
            if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1') {
                return $cc;
            }
        }

        return $this->lookup($ip);
    }

    public function lookup(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || !$this->open()) {
            return '';
        }
        // IPv4-mapped IPv6 (::ffff:1.2.3.4) is looked up as IPv4.
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        [$count, $offset, $size] = strlen($bin) === 4 ? [$this->v4Count, $this->v4Offset, 6] : [$this->v6Count, $this->v6Offset, 18];
        $keyLen = $size - 2;

        // Binary search for the last record whose start <= ip.
        $lo = 0;
        $hi = $count - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            fseek($this->fh, $offset + $mid * $size);
            $start = (string) fread($this->fh, $keyLen);
            if (strcmp($start, $bin) <= 0) {
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($found < 0) {
            return '';
        }
        fseek($this->fh, $offset + $found * $size + $keyLen);
        $cc = (string) fread($this->fh, 2);

        return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'ZZ' ? $cc : '';
    }

    private function open(): bool
    {
        if ($this->opened) {
            return is_resource($this->fh);
        }
        $this->opened = true;
        if (!is_file($this->path) || ($fh = @fopen($this->path, 'rb')) === false) {
            return false;
        }
        $head = (string) fread($fh, 18);
        if (strlen($head) < 18 || !str_starts_with($head, self::MAGIC)) {
            fclose($fh);

            return false;
        }
        ['a' => $v4, 'b' => $v6, 'c' => $labelLen] = unpack('Na/Nb/nc', substr($head, 8));
        $this->label = $labelLen > 0 ? (string) fread($fh, $labelLen) : '';
        $this->fh = $fh;
        $this->v4Count = $v4;
        $this->v6Count = $v6;
        $this->v4Offset = 18 + $labelLen;
        $this->v6Offset = $this->v4Offset + $v4 * 6;

        return true;
    }

    // ------------------------------------------------------------------ building

    /**
     * Builds the database from a DB-IP style CSV (start,end,country), plain or .gz.
     * Streams line by line, so it runs within shared-hosting memory limits.
     *
     * @return array{v4: int, v6: int}
     */
    public static function build(string $csvPath, string $outPath, string $label): array
    {
        $in = @fopen((str_ends_with($csvPath, '.gz') ? 'compress.zlib://' : '') . $csvPath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Can't read {$csvPath}");
        }
        $tmp4 = fopen('php://temp/maxmemory:8388608', 'w+b');
        $tmp6 = fopen('php://temp/maxmemory:8388608', 'w+b');
        $counts = [4 => 0, 16 => 0];
        $prevEnd = [4 => null, 16 => null];

        while (($row = fgetcsv($in, 200, ',', '"', '')) !== false) {
            if (count($row) < 3) {
                continue;
            }
            $start = @inet_pton(trim((string) $row[0]));
            $end = @inet_pton(trim((string) $row[1]));
            $cc = strtoupper(trim((string) $row[2]));
            if ($start === false || $end === false || strlen($start) !== strlen($end)) {
                continue;
            }
            $len = strlen($start);
            $out = $len === 4 ? $tmp4 : $tmp6;
            // Mark the gap between the previous range and this one as unknown.
            if ($prevEnd[$len] !== null && strcmp($start, self::increment($prevEnd[$len])) > 0) {
                fwrite($out, self::increment($prevEnd[$len]) . '--');
                $counts[$len]++;
            }
            if ($prevEnd[$len] !== null && strcmp($start, $prevEnd[$len]) <= 0) {
                continue; // overlapping or unsorted line: skip rather than corrupt the order
            }
            fwrite($out, $start . (preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '--'));
            $counts[$len]++;
            $prevEnd[$len] = $end;
        }
        fclose($in);
        foreach ([4 => $tmp4, 16 => $tmp6] as $len => $out) {
            if ($prevEnd[$len] !== null && $prevEnd[$len] !== str_repeat("\xff", $len)) {
                fwrite($out, self::increment($prevEnd[$len]) . '--');
                $counts[$len]++;
            }
        }
        if ($counts[4] + $counts[16] === 0) {
            throw new \RuntimeException('The file had no usable rows. Expected CSV lines like 1.0.0.0,1.0.0.255,AU');
        }

        $label = substr($label, 0, 200);
        $partial = $outPath . '.part';
        $fh = fopen($partial, 'wb') ?: throw new \RuntimeException("Can't write {$outPath}");
        fwrite($fh, self::MAGIC . pack('NNn', $counts[4], $counts[16], strlen($label)) . $label);
        foreach ([$tmp4, $tmp6] as $tmp) {
            rewind($tmp);
            stream_copy_to_stream($tmp, $fh);
            fclose($tmp);
        }
        fclose($fh);
        rename($partial, $outPath);

        return ['v4' => $counts[4], 'v6' => $counts[16]];
    }

    /** Big-endian binary string + 1. */
    private static function increment(string $bin): string
    {
        for ($i = strlen($bin) - 1; $i >= 0; $i--) {
            $byte = ord($bin[$i]);
            if ($byte < 255) {
                $bin[$i] = chr($byte + 1);

                return $bin;
            }
            $bin[$i] = "\0";
        }

        return $bin;
    }

    /** ISO code → name, for the dashboard. Unknown codes are shown as the code. */
    public static function name(string $cc): string
    {
        if ($cc === '') {
            return 'Unknown';
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $cc, 'en');
            if (is_string($name) && $name !== '' && $name !== $cc) {
                return $name;
            }
        }

        return $cc;
    }

    /** Flag emoji from a country code (regional indicator symbols). */
    public static function flag(string $cc): string
    {
        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            return '';
        }

        return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
    }
}
