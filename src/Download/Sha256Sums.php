<?php

declare(strict_types=1);

namespace Dynoxide\Download;

use Dynoxide\Exception\DownloadException;

/**
 * Reads a release's sha256sums.txt, as written by sha256sum: one
 * "<hash>  <name>" line per file, or "<hash> *<name>" in binary mode.
 */
final class Sha256Sums
{
    /**
     * Every line must be readable and every name listed once; anything else
     * means the file isn't what the release published.
     *
     * @return array<string, string> file name to lower-case hex hash
     */
    public static function parse(string $text): array
    {
        $listed = [];
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](\S+)$/', $line, $m) !== 1) {
                throw new DownloadException("sha256sums.txt has a line that can't be read: {$line}");
            }
            if (isset($listed[$m[2]])) {
                throw new DownloadException("sha256sums.txt lists {$m[2]} twice.");
            }
            $listed[$m[2]] = $m[1];
        }

        return $listed;
    }
}
