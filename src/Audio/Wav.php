<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Audio;

use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * RIFF WAVE handling: header parsing, streaming joins with rewritten sizes.
 */
final class Wav
{
    private const BLOCK = 65536;

    /**
     * @return array{fmt: string, channels: int, sampleRate: int, bits: int, byteRate: int, dataOffset: int, dataSize: int}
     * @throws InvalidAudioException|StorageException
     */
    public static function parse(string $path): array
    {
        $size = is_file($path) ? filesize($path) : false;
        $in = $size === false ? false : fopen($path, 'rb');
        if ($in === false || $size === false) {
            throw new StorageException('Cannot read a wav chunk.');
        }
        try {
            $riff = (string) fread($in, 12);
            if (strlen($riff) < 12 || strncmp($riff, 'RIFF', 4) !== 0 || substr($riff, 8, 4) !== 'WAVE') {
                throw new InvalidAudioException('Not a RIFF WAVE file.');
            }
            $fmt = null;
            $pos = 12;
            while ($pos + 8 <= $size) {
                fseek($in, $pos);
                $chunkHeader = (string) fread($in, 8);
                $id = substr($chunkHeader, 0, 4);
                $unpacked = unpack('V', substr($chunkHeader, 4, 4));
                $chunkSize = is_array($unpacked) ? (int) $unpacked[1] : 0;
                if ($id === 'fmt ') {
                    $fmt = (string) fread($in, max(1, min($chunkSize, 64)));
                } elseif ($id === 'data') {
                    if ($fmt === null || strlen($fmt) < 16) {
                        throw new InvalidAudioException('WAVE data before its format chunk.');
                    }
                    $fields = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', substr($fmt, 0, 16));
                    if (!is_array($fields)) {
                        throw new InvalidAudioException('Unreadable WAVE format chunk.');
                    }
                    $available = $size - ($pos + 8);
                    $dataSize = ($chunkSize === 0 || $chunkSize > $available) ? $available : $chunkSize;

                    return [
                        'fmt' => $fmt,
                        'channels' => (int) $fields['channels'],
                        'sampleRate' => (int) $fields['sampleRate'],
                        'bits' => (int) $fields['bits'],
                        'byteRate' => (int) $fields['byteRate'],
                        'dataOffset' => $pos + 8,
                        'dataSize' => $dataSize,
                    ];
                }
                $pos += 8 + $chunkSize + ($chunkSize % 2);
            }
            throw new InvalidAudioException('WAVE file has no data chunk.');
        } finally {
            fclose($in);
        }
    }

    public static function duration(string $path): float
    {
        $info = self::parse($path);

        return $info['byteRate'] > 0 ? $info['dataSize'] / $info['byteRate'] : 0.0;
    }

    /**
     * Join wav files that share sample rate, channels and bit depth.
     *
     * @param list<string> $inputs
     * @return array{duration: float, dataSize: int}
     * @throws InvalidAudioException|StorageException
     */
    public static function join(array $inputs, string $output): array
    {
        if ($inputs === []) {
            throw new InvalidAudioException('Nothing to join.');
        }
        $infos = [];
        foreach ($inputs as $input) {
            $infos[] = self::parse($input);
        }
        $first = $infos[0];
        $total = 0;
        foreach ($infos as $info) {
            if (
                $info['sampleRate'] !== $first['sampleRate']
                || $info['channels'] !== $first['channels']
                || $info['bits'] !== $first['bits']
            ) {
                throw new InvalidAudioException('WAVE chunks differ in sample rate, channels or bit depth.');
            }
            $total += $info['dataSize'];
        }
        $fmt = substr($first['fmt'], 0, 16);
        $out = fopen($output, 'wb');
        if ($out === false) {
            throw new StorageException('Cannot open the joined file for writing.');
        }
        try {
            self::write($out, 'RIFF' . pack('V', 4 + 8 + strlen($fmt) + 8 + $total) . 'WAVE');
            self::write($out, 'fmt ' . pack('V', strlen($fmt)) . $fmt);
            self::write($out, 'data' . pack('V', $total));
            foreach ($inputs as $i => $input) {
                $in = fopen($input, 'rb');
                if ($in === false) {
                    throw new StorageException('Cannot read a wav chunk.');
                }
                fseek($in, $infos[$i]['dataOffset']);
                $left = $infos[$i]['dataSize'];
                while ($left > 0) {
                    $block = (string) fread($in, min(self::BLOCK, $left));
                    if ($block === '') {
                        break;
                    }
                    self::write($out, $block);
                    $left -= strlen($block);
                }
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        return [
            'duration' => $first['byteRate'] > 0 ? $total / $first['byteRate'] : 0.0,
            'dataSize' => $total,
        ];
    }

    /** 8 kHz, 8-bit, mono silence. */
    public static function silence(float $seconds): string
    {
        $samples = max(1, (int) ceil($seconds * 8000));
        $fmt = pack('vvVVvv', 1, 1, 8000, 8000, 1, 8);

        return 'RIFF' . pack('V', 4 + 8 + 16 + 8 + $samples) . 'WAVE'
            . 'fmt ' . pack('V', 16) . $fmt
            . 'data' . pack('V', $samples) . str_repeat("\x80", $samples);
    }

    /**
     * @param resource $out
     */
    private static function write($out, string $bytes): void
    {
        if (fwrite($out, $bytes) !== strlen($bytes)) {
            throw new StorageException('Short write while joining audio (disk full?).');
        }
    }
}
