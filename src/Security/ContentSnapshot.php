<?php
declare(strict_types=1);
namespace SohoPHP\SoFinder\Security;
use SohoPHP\SoFinder\Exception\SoFinderException;

/** Hash and serve the same bounded private snapshot, including nonseekable storage streams. */
final class ContentSnapshot
{
    /**
     * @param resource $source
     * @return array{sha256:string,stream:resource}
     */
    public static function capture(mixed $source, int $maximumBytes): array
    {
        if (!is_resource($source)) throw new SoFinderException('Unable to read the file revision.', 'file_revision_failed', 500);
        $snapshot = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($snapshot === false) { fclose($source); throw new SoFinderException('Unable to stage the file revision.', 'file_revision_failed', 500); }
        try {
            $hash = hash_init('sha256'); $bytes = 0;
            while (!feof($source)) {
                $chunk = fread($source, 65_536);
                if ($chunk === false || ($chunk === '' && !feof($source))) throw new SoFinderException('Unable to read the file revision.', 'file_revision_failed', 500);
                $bytes += strlen($chunk);
                if ($bytes > $maximumBytes) throw new SoFinderException('The file exceeds the protected-content budget.', 'file_too_large', 413);
                if ($chunk !== '' && fwrite($snapshot, $chunk) !== strlen($chunk)) throw new SoFinderException('Unable to stage the file revision.', 'file_revision_failed', 500);
                hash_update($hash, $chunk);
            }
            if (!rewind($snapshot)) throw new SoFinderException('Unable to stage the file revision.', 'file_revision_failed', 500);
            return ['sha256' => hash_final($hash), 'stream' => $snapshot];
        } catch (\Throwable $error) {
            fclose($snapshot); throw $error;
        } finally { fclose($source); }
    }
}
