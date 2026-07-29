<?php
class DocxTemplate
{
    private string $sourceFile;
    private array $placeholders = [];

    public function __construct(string $docxContent)
    {
        $tmpDir = TEMP_DIR;
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $this->sourceFile = $tmpDir . uniqid('docx_', true) . '.docx';
        file_put_contents($this->sourceFile, $docxContent);
    }

    public function setPlaceholders(array $values): void
    {
        $this->placeholders = $values;
    }

    public function process(): string
    {
        $zip = new ZipArchive();
        if ($zip->open($this->sourceFile) !== true) {
            throw new RuntimeException('Не удалось открыть DOCX как ZIP');
        }

        // Main document content
        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new RuntimeException('word/document.xml не найден в шаблоне');
        }

        $xml = $this->replacePlaceholders($xml);

        // Also process headers/footers
        for ($i = 1; $i <= 3; $i++) {
            $headerPath = "word/header{$i}.xml";
            if ($zip->locateName($headerPath) !== false) {
                $headerXml = $zip->getFromName($headerPath);
                $headerXml = $this->replacePlaceholders($headerXml);
                $zip->deleteName($headerPath);
                $zip->addFromString($headerPath, $headerXml);
            }

            $footerPath = "word/footer{$i}.xml";
            if ($zip->locateName($footerPath) !== false) {
                $footerXml = $zip->getFromName($footerPath);
                $footerXml = $this->replacePlaceholders($footerXml);
                $zip->deleteName($footerPath);
                $zip->addFromString($footerPath, $footerXml);
            }
        }

        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);

        // Update docProps/app.xml
        $appXml = $zip->getFromName('docProps/app.xml');
        if ($appXml !== false) {
            $appXml = str_replace('Template', APP_NAME . ' v' . APP_VERSION, $appXml);
            $zip->deleteName('docProps/app.xml');
            $zip->addFromString('docProps/app.xml', $appXml);
        }

        $zip->close();

        $output = file_get_contents($this->sourceFile);
        unlink($this->sourceFile);

        return $output;
    }

    private function replacePlaceholders(string $xml): string
    {
        foreach ($this->placeholders as $key => $value) {
            $placeholder = '{' . $key . '}';
            $value = htmlspecialchars((string)$value, ENT_XML1, 'UTF-8');

            // Try simple replace first
            $xml = str_replace($placeholder, $value, $xml);

            // Handle placeholders split across <w:r> elements by Word
            $pattern = '/<w:r[^>]*>.*?' . preg_quote($placeholder, '/') . '.*?<\/w:r>/s';
            $xml = preg_replace_callback($pattern, function ($m) use ($value) {
                return str_replace('{' . $placeholder . '}', $value, $m[0]);
            }, $xml);
        }

        return $xml;
    }

    public function __destruct()
    {
        if ($this->sourceFile && file_exists($this->sourceFile)) {
            unlink($this->sourceFile);
        }
    }
}
