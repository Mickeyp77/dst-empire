<?php
/**
 * PandocConverter — thin wrapper around the Pandoc CLI for format conversion.
 *
 * All methods return false (never throw) when Pandoc is absent or conversion
 * fails. System degrades gracefully to .md-only output. CARL rule 4: no
 * SaaS doc generation services — only OSS CLI tools (Pandoc, xelatex/wkhtmltopdf).
 *
 * PDF engine priority:
 *   1. xelatex (brew install --cask mactex OR basictex)
 *   2. wkhtmltopdf (brew install wkhtmltopdf)
 *   3. falls back to false if neither found
 */

namespace Mnmsos\Empire\Docs;

class PandocConverter
{
    /** Seconds before proc_open read loop aborts. */
    private const TIMEOUT_S = 120;

    /** Common extra args for DOCX output to improve formatting. */
    private const DOCX_ARGS = [
        '--standalone',
        '--wrap=none',
    ];

    /** Common extra args for all PDF engines. */
    private const PDF_COMMON_ARGS = [
        '--standalone',
        '-V', 'geometry:margin=1in',
        '-V', 'fontsize=11pt',
    ];

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Check whether Pandoc is available on PATH.
     */
    public static function available(): bool
    {
        $pandoc = self::findExecutable('pandoc');
        return $pandoc !== null;
    }

    /**
     * Convert a Markdown file to DOCX.
     *
     * @param string $mdPath    Absolute path to input .md file.
     * @param string $docxPath  Absolute path for output .docx file.
     * @return bool  true on success, false on any error.
     */
    public function mdToDocx(string $mdPath, string $docxPath): bool
    {
        $pandoc = self::findExecutable('pandoc');
        if ($pandoc === null) {
            error_log('[PandocConverter] pandoc not found on PATH — skipping .docx generation');
            return false;
        }
        if (!is_readable($mdPath)) {
            error_log("[PandocConverter] Input not readable: {$mdPath}");
            return false;
        }

        $args = array_merge(
            [$pandoc],
            self::DOCX_ARGS,
            ['--from=markdown', '--to=docx', '-o', $docxPath, $mdPath]
        );

        return $this->runCommand($args);
    }

    /**
     * Convert a Markdown file to PDF.
     *
     * Tries xelatex first, falls back to wkhtmltopdf.
     *
     * @param string $mdPath   Absolute path to input .md file.
     * @param string $pdfPath  Absolute path for output .pdf file.
     * @return bool  true on success, false on any error.
     */
    public function mdToPdf(string $mdPath, string $pdfPath): bool
    {
        $pandoc = self::findExecutable('pandoc');
        if ($pandoc === null) {
            error_log('[PandocConverter] pandoc not found on PATH — skipping .pdf generation');
            return false;
        }
        if (!is_readable($mdPath)) {
            error_log("[PandocConverter] Input not readable: {$mdPath}");
            return false;
        }

        // Try xelatex
        if (self::findExecutable('xelatex') !== null) {
            $args = array_merge(
                [$pandoc],
                self::PDF_COMMON_ARGS,
                ['--pdf-engine=xelatex', '--from=markdown', '-o', $pdfPath, $mdPath]
            );
            if ($this->runCommand($args)) {
                return true;
            }
            error_log('[PandocConverter] xelatex render failed — trying wkhtmltopdf fallback');
        }

        // Try wkhtmltopdf
        if (self::findExecutable('wkhtmltopdf') !== null) {
            // Pandoc's wkhtmltopdf engine converts md→html→pdf via wkhtmltopdf
            $args = array_merge(
                [$pandoc],
                self::PDF_COMMON_ARGS,
                ['--pdf-engine=wkhtmltopdf', '--from=markdown', '-o', $pdfPath, $mdPath]
            );
            if ($this->runCommand($args)) {
                return true;
            }
            error_log('[PandocConverter] wkhtmltopdf render failed');
        }

        error_log('[PandocConverter] No PDF engine available (xelatex, wkhtmltopdf). '
            . 'Install via: brew install --cask basictex && brew install wkhtmltopdf');
        return false;
    }

    /**
     * Convert a Markdown file to HTML.
     *
     * @param string $mdPath   Absolute path to input .md file.
     * @param string $htmlPath Absolute path for output .html file.
     * @return bool  true on success, false on any error.
     */
    public function mdToHtml(string $mdPath, string $htmlPath): bool
    {
        $pandoc = self::findExecutable('pandoc');
        if ($pandoc === null) {
            error_log('[PandocConverter] pandoc not found on PATH — skipping .html generation');
            return false;
        }
        if (!is_readable($mdPath)) {
            error_log("[PandocConverter] Input not readable: {$mdPath}");
            return false;
        }

        $args = [
            $pandoc,
            '--standalone',
            '--from=markdown',
            '--to=html5',
            '-o', $htmlPath,
            $mdPath,
        ];

        return $this->runCommand($args);
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    /**
     * Run a command via proc_open with a timeout.
     *
     * @param string[] $args  Command + argument list (NOT shell-escaped strings).
     * @return bool  true if exit code == 0.
     */
    private function runCommand(array $args): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $proc = proc_open($args, $descriptors, $pipes);
        if (!is_resource($proc)) {
            error_log('[PandocConverter] proc_open failed for: ' . $args[0]);
            return false;
        }

        fclose($pipes[0]); // no stdin

        $stderr    = '';
        $startTime = time();

        // Read stdout/stderr without blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $stderr .= (string)fread($pipes[2], 4096);
            $status  = proc_get_status($proc);
            if (!$status['running']) {
                $stderr .= (string)stream_get_contents($pipes[2]);
                break;
            }
            if ((time() - $startTime) > self::TIMEOUT_S) {
                proc_terminate($proc);
                error_log('[PandocConverter] Command timed out after ' . self::TIMEOUT_S . 's');
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return false;
            }
            usleep(50_000); // 50ms poll
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            error_log("[PandocConverter] Exit {$exitCode}: " . trim($stderr));
            return false;
        }

        return true;
    }

    /**
     * Find an executable on PATH using `which` (macOS/Linux).
     * Returns full path string or null.
     */
    private static function findExecutable(string $name): ?string
    {
        // Static cache so available() + subsequent calls don't re-exec
        static $cache = [];
        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }
        $path = trim((string)shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
        $cache[$name] = ($path !== '') ? $path : null;
        return $cache[$name];
    }
}
