<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Audio;

use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * MPEG audio frame handling: tag stripping, VBR header removal, frame count
 * and duration, and streaming joins.
 *
 * Only frame headers are parsed; audio data is copied untouched.
 */
final class Mp3
{
    /** @var array<string, list<int>> kbps by "version-layer" */
    private const BITRATES = [
        '1-1' => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
        '1-2' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
        '1-3' => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        '2-1' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
        '2-2' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        '2-3' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];

    /** @var array<int, list<int>> by version id (3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5) */
    private const SAMPLE_RATES = [
        3 => [44100, 48000, 32000],
        2 => [22050, 24000, 16000],
        0 => [11025, 12000, 8000],
    ];

    private const RESYNC_LIMIT = 65536;
    private const BLOCK = 65536;

    /**
     * Parse a 4-byte frame header.
     *
     * @return array{length: int, samples: int, sampleRate: int, version: int, layer: int, mono: bool, crc: bool}|null
     */
    public static function parseHeader(string $bytes): ?array
    {
        if (strlen($bytes) < 4) {
            return null;
        }
        $b0 = ord($bytes[0]);
        $b1 = ord($bytes[1]);
        $b2 = ord($bytes[2]);
        $b3 = ord($bytes[3]);
        if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
            return null;
        }
        $versionId = ($b1 >> 3) & 0x03;
        $layerId = ($b1 >> 1) & 0x03;
        $bitrateIndex = ($b2 >> 4) & 0x0F;
        $rateIndex = ($b2 >> 2) & 0x03;
        if ($versionId === 1 || $layerId === 0 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null;
        }
        $layer = 4 - $layerId;
        $tableVersion = $versionId === 3 ? 1 : 2;
        $bitrate = self::BITRATES[$tableVersion . '-' . $layer][$bitrateIndex] * 1000;
        $sampleRate = self::SAMPLE_RATES[$versionId][$rateIndex];
        $padding = ($b2 >> 1) & 0x01;
        if ($layer === 1) {
            $length = (intdiv(12 * $bitrate, $sampleRate) + $padding) * 4;
            $samples = 384;
        } elseif ($layer === 2) {
            $length = intdiv(144 * $bitrate, $sampleRate) + $padding;
            $samples = 1152;
        } else {
            $factor = $versionId === 3 ? 144 : 72;
            $length = intdiv($factor * $bitrate, $sampleRate) + $padding;
            $samples = $versionId === 3 ? 1152 : 576;
        }

        return [
            'length' => $length,
            'samples' => $samples,
            'sampleRate' => $sampleRate,
            'version' => $versionId,
            'layer' => $layer,
            'mono' => (($b3 >> 6) & 0x03) === 3,
            'crc' => ($b1 & 0x01) === 0,
        ];
    }

    /**
     * Count frames and compute duration without copying anything.
     *
     * @return array{frames: int, duration: float}
     */
    public static function scan(string $path): array
    {
        return self::walk($path, null);
    }

    /**
     * Join chunk files into one file, streaming.
     *
     * Each input loses its ID3v2 and ID3v1 tags and any Xing, Info or VBRI
     * frame. One fresh ID3v2.4 tag with the title is written first when a
     * title is given.
     *
     * @param list<string> $inputs
     * @return array{frames: int, duration: float}
     * @throws InvalidAudioException|StorageException
     */
    public static function join(array $inputs, string $output, ?string $title = null): array
    {
        $out = fopen($output, 'wb');
        if ($out === false) {
            throw new StorageException('Cannot open the joined file for writing.');
        }
        $frames = 0;
        $duration = 0.0;
        try {
            if ($title !== null && $title !== '') {
                self::write($out, self::id3Title($title));
            }
            foreach ($inputs as $input) {
                $info = self::walk($input, $out);
                $frames += $info['frames'];
                $duration += $info['duration'];
            }
        } finally {
            fclose($out);
        }

        return ['frames' => $frames, 'duration' => $duration];
    }

    /**
     * Build an ID3v2.4 tag holding one TIT2 frame.
     */
    public static function id3Title(string $title): string
    {
        $payload = "\x03" . $title;
        $frame = 'TIT2' . self::syncsafe(strlen($payload)) . "\x00\x00" . $payload;

        return 'ID3' . "\x04\x00" . "\x00" . self::syncsafe(strlen($frame)) . $frame;
    }

    /**
     * Build a run of silent MPEG-1 Layer III frames (32 kbps, 44.1 kHz,
     * mono). Zeroed side information decodes as silence.
     */
    public static function silence(float $seconds): string
    {
        $frames = max(1, (int) ceil($seconds * 44100 / 1152));
        $frame = "\xFF\xFB\x10\xC0" . str_repeat("\x00", 100);

        return str_repeat($frame, $frames);
    }

    private static function syncsafe(int $value): string
    {
        return chr(($value >> 21) & 0x7F) . chr(($value >> 14) & 0x7F) . chr(($value >> 7) & 0x7F) . chr($value & 0x7F);
    }

    /**
     * Walk audio frames of one file, optionally copying them to $out.
     * Reads and writes in 64 KiB blocks, so memory stays flat whatever the
     * file size.
     *
     * @param resource|null $out
     * @return array{frames: int, duration: float}
     * @throws InvalidAudioException|StorageException
     */
    private static function walk(string $path, $out): array
    {
        $size = is_file($path) ? filesize($path) : false;
        $in = $size === false ? false : fopen($path, 'rb');
        if ($in === false || $size === false) {
            throw new StorageException('Cannot read an mp3 chunk.');
        }
        try {
            $start = 0;
            $head = (string) fread($in, 10);
            if (strncmp($head, 'ID3', 3) === 0 && strlen($head) === 10) {
                $tagSize = ((ord($head[6]) & 0x7F) << 21) | ((ord($head[7]) & 0x7F) << 14)
                    | ((ord($head[8]) & 0x7F) << 7) | (ord($head[9]) & 0x7F);
                $footer = (ord($head[5]) & 0x10) !== 0 ? 10 : 0;
                $start = 10 + $tagSize + $footer;
            }
            $end = $size;
            if ($size >= 128) {
                fseek($in, $size - 128);
                if ((string) fread($in, 3) === 'TAG') {
                    $end = $size - 128;
                }
            }
            fseek($in, $start);
            $buffer = '';
            $bufferStart = $start;
            $pending = '';
            $pos = $start;
            $frames = 0;
            $duration = 0.0;
            $first = true;
            $skipped = 0;
            while ($pos + 4 <= $end) {
                $offset = $pos - $bufferStart;
                $loadedEnd = $bufferStart + strlen($buffer);
                if ($offset + 8192 > strlen($buffer) && $loadedEnd < $end) {
                    $buffer = substr($buffer, $offset) . (string) fread($in, max(1, min(self::BLOCK, $end - $loadedEnd)));
                    $bufferStart = $pos;
                    $offset = 0;
                }
                $header = self::parseHeader(substr($buffer, $offset, 4));
                if ($header === null || $pos + $header['length'] > $end) {
                    // Junk between frames: step forward until a frame header.
                    $pos++;
                    if (++$skipped > self::RESYNC_LIMIT) {
                        break;
                    }
                    continue;
                }
                $skipped = 0;
                $frame = substr($buffer, $offset, $header['length']);
                $pos += $header['length'];
                if ($first) {
                    $first = false;
                    if (self::isVbrHeaderFrame($frame, $header)) {
                        continue;
                    }
                }
                $frames++;
                // Per frame, so a stream that changes sample rate part-way
                // (two chunks from different settings) still measures right.
                $duration += $header['samples'] / $header['sampleRate'];
                if ($out !== null) {
                    $pending .= $frame;
                    if (strlen($pending) >= self::BLOCK) {
                        self::write($out, $pending);
                        $pending = '';
                    }
                }
            }
            if ($out !== null && $pending !== '') {
                self::write($out, $pending);
            }
            if ($frames === 0) {
                throw new InvalidAudioException('No MPEG audio frames found.');
            }

            return ['frames' => $frames, 'duration' => $duration];
        } finally {
            fclose($in);
        }
    }

    /**
     * @param array{length: int, samples: int, sampleRate: int, version: int, layer: int, mono: bool, crc: bool} $header
     */
    private static function isVbrHeaderFrame(string $frame, array $header): bool
    {
        if (substr($frame, 36, 4) === 'VBRI') {
            return true;
        }
        if ($header['layer'] !== 3) {
            return false;
        }
        if ($header['version'] === 3) {
            $side = $header['mono'] ? 17 : 32;
        } else {
            $side = $header['mono'] ? 9 : 17;
        }
        $offset = 4 + $side + ($header['crc'] ? 2 : 0);
        $tag = substr($frame, $offset, 4);

        return $tag === 'Xing' || $tag === 'Info';
    }

    /**
     * @param resource $out
     */
    private static function write($out, string $bytes): void
    {
        $written = fwrite($out, $bytes);
        if ($written !== strlen($bytes)) {
            throw new StorageException('Short write while joining audio (disk full?).');
        }
    }
}
