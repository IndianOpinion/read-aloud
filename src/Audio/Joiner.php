<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Audio;

use IndianOpinion\ReadAloud\Exception\FormatNotJoinableException;
use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * Joins chunk files into one file and measures its duration.
 */
final class Joiner
{
    /**
     * @param list<string> $inputs
     * @return float Duration in seconds measured from the joined file (0 when unknown).
     * @throws FormatNotJoinableException|StorageException
     */
    public function join(array $inputs, string $format, string $output, ?string $title = null, bool $providerJoins = false): float
    {
        if (count($inputs) === 1) {
            if (!copy($inputs[0], $output)) {
                throw new StorageException('Cannot copy the audio file.');
            }
            return $this->measure($output, $format);
        }
        if ($format === AudioFormat::MP3) {
            return Mp3::join($inputs, $output, $title)['duration'];
        }
        if ($format === AudioFormat::WAV) {
            return Wav::join($inputs, $output)['duration'];
        }
        if ($providerJoins) {
            $this->concatenate($inputs, $output);
            return $this->measure($output, $format);
        }
        throw new FormatNotJoinableException('The plugin cannot join ' . $format . ' chunks.');
    }

    /**
     * Plain byte concatenation, used only when the adapter declares that its
     * format survives it (for example chained Ogg streams).
     *
     * @param list<string> $inputs
     */
    private function concatenate(array $inputs, string $output): void
    {
        $out = fopen($output, 'wb');
        if ($out === false) {
            throw new StorageException('Cannot open the joined file for writing.');
        }
        try {
            foreach ($inputs as $input) {
                $in = fopen($input, 'rb');
                if ($in === false) {
                    throw new StorageException('Cannot read a chunk.');
                }
                while (!feof($in)) {
                    $block = (string) fread($in, 65536);
                    if ($block !== '' && fwrite($out, $block) !== strlen($block)) {
                        fclose($in);
                        throw new StorageException('Short write while joining audio (disk full?).');
                    }
                }
                fclose($in);
            }
        } finally {
            fclose($out);
        }
    }

    /** Duration from the file itself, or 0 when the format is not measurable. */
    public function measure(string $path, string $format): float
    {
        if ($format === AudioFormat::MP3) {
            return Mp3::scan($path)['duration'];
        }
        if ($format === AudioFormat::WAV) {
            return Wav::duration($path);
        }

        return 0.0;
    }
}
