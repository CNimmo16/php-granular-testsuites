<?php

namespace Cnimmo\GranularTestsuites;

use DOMDocument;
use DOMNode;

function rglob($pattern, $flags = 0)
{
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
        $files = array_merge(
            [],
            ...[$files, rglob($dir . "/" . basename($pattern), $flags)]
        );
    }
    return $files;
}

class TestsuiteGranulariser
{
    private string $configFileParentDirectoryPath;
    private DOMDocument $doc;

    public function __construct(string $configPath)
    {
        if (!file_exists($configPath)) {
            echo 'ERROR: Config file does not exist: ' . $configPath . PHP_EOL;
            exit(1);
        }
        $this->configFileParentDirectoryPath = dirname(realpath($configPath));

        $this->doc = new DOMDocument();
        $this->doc->formatOutput = true;
        $this->doc->preserveWhiteSpace = false;
        $this->doc->load($configPath);
    }

    public function granularise(string | null $outputPath, bool $overwrite): string
    {
        /**
         * @var DOMNode $testSuitesNode
         */
        $testSuitesNode = $this->doc->getElementsByTagName('testsuites')[0];

        $allTestFilePaths = [];
        /**
         * @var DOMNode $testSuiteNode
         */
        foreach ($testSuitesNode->childNodes as $testSuiteNode) {
            /**
             * @var DOMNode $child
             */
            foreach ($testSuiteNode->childNodes as $child) {
                switch ($child->nodeName) {
                    case 'directory':
                        $prefix = $child->attributes->getNamedItem('prefix')?->nodeValue ?? '';
                        $suffix = $child->attributes->getNamedItem('suffix')?->nodeValue ?? '.php';
                        $directoryPath = realpath($this->configFileParentDirectoryPath . '/' . $child->textContent);
                        array_push($allTestFilePaths, ...$this->getTestFilePathsInDirectory($directoryPath, $prefix, $suffix));
                        break;
                    case 'file':
                        array_push($allTestFilePaths, $child->textContent);
                        break;
                }
            }
        }
        while ($testSuitesNode->hasChildNodes()) {
            $testSuitesNode->removeChild($testSuitesNode->firstChild);
        }

        $testSuites = array_map(function ($filePath) {
            $testSuite = $this->doc->createElement('testsuite');
            $testSuite->setAttribute('name', $filePath);
            $file = $this->doc->createElement('file', $filePath);
            $testSuite->appendChild($file);
            return $testSuite;
        }, $allTestFilePaths);

        foreach ($testSuites as $testSuite) {
            $testSuitesNode->appendChild($testSuite);
        }

        $xml = $this->doc->saveXML();

        if (!$outputPath) {
            echo 'WARNING: Output file not specified. Defaulting to stdout.' . PHP_EOL . PHP_EOL;
            echo $xml;
        } else {
            if (!$overwrite && file_exists($outputPath)) {
                echo 'ERROR: Output file already exists. Use --overwrite to overwrite.' . PHP_EOL;
                exit(1);
            }
            if (file_exists($outputPath)) {
                echo 'WARNING: Output file already exists. Overwriting.' . PHP_EOL;
            }
            echo '> Writing to ' . $outputPath . PHP_EOL;
            file_put_contents($outputPath, $xml);
            echo 'Success!' . PHP_EOL;
        }

        return $xml;
    }

    private function getTestFilePathsInDirectory(string $directoryPath, string $prefix, string $suffix)
    {
        $glob = $directoryPath . "/**/$prefix*$suffix";
        $allFilesInDirectory = rglob($glob);
        
        return array_map(function ($absolutePath) {
            return str_replace($this->configFileParentDirectoryPath, '.', $absolutePath);
        }, $allFilesInDirectory);
    }
}
