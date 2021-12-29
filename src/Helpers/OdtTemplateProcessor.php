<?php
/*
 * Copyright (C) 2021. Def Studio
 *  Unauthorized copying of this file, via any medium is strictly prohibited
 *  Authors: Fabio Ivona <fabio.ivona@defstudio.it> & Daniele Romeo <danieleromeo@defstudio.it>
 */

/** @noinspection PhpUnhandledExceptionInspection */

namespace DefStudio\TemplateProcessor\Helpers;

use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use ZipArchive;

class OdtTemplateProcessor
{
    private string $temporary_template_file;
    private ZipArchive $zip;
    private string $content;

    public function __construct(string $template)
    {
        $this->temporary_template_file = tempnam(sys_get_temp_dir(), 'TemplateProcessor');

        if (false === $this->temporary_template_file) {
            throw new CreateTemporaryFileException();
        }

        if (false === copy($template, $this->temporary_template_file)) {
            throw new CopyFileException($template, $this->temporary_template_file);
        }

        $this->zip = new ZipArchive();
        $this->zip->open($this->temporary_template_file);

        $this->content = $this->zip->getFromName('content.xml');
    }

    public function setValue(string $key, string $value)
    {
        $this->content = str_replace('${'.$key.'}', $value, $this->content);
        $this->content = str_replace('{'.$key.'}', $value, $this->content);
    }

    public function deleteBlock(string $blockname)
    {
        $this->cloneBlock($blockname, 0);
    }

    public function cloneBlock(string $blockname, int $clones = 1, array $variableReplacements = [])
    {
        $matches = [];

        $regexp = '/';               //Regexp start
        $regexp .= '<[\w>\"=\-: ]*'; //<tag> before section opening
        $regexp .= "{{$blockname}}";      //section keyword
        $regexp .= '<\/text:p>';     //</tag> after section opening
        $regexp .= '(.*)';           //text to repeat
        $regexp .= '<[\w>\"=\-: ]*'; //<tag> before section closing
        $regexp .= "{\/{$blockname}}";    //section section keyword
        $regexp .= '<\/text:p>';    //</tag> after section opening
        $regexp .= '/m';            //Regexp end (m = multiline)

        preg_match($regexp, $this->content, $matches);
        $section_to_replace = $matches[0] ?? '';
        $text_to_repeat = $matches[1] ?? '';

        $text_to_replace = "";
        for ($copy = 0; $copy < $clones; $copy++) {
            $text_to_copy = $text_to_repeat;

            $variables_to_replace = $variableReplacements[$copy] ?? [];

            foreach ($variables_to_replace as $key => $value) {
                $text_to_copy = str_replace('${'.$key.'}', $value, $text_to_copy);
                $text_to_copy = str_replace('{'.$key.'}', $value, $text_to_copy);
            }

            $text_to_replace .= $text_to_copy;
        }

        $this->content = preg_replace($regexp, $text_to_replace, $this->content);
    }

    public function saveAs($compiled_file)
    {
        $this->zip->deleteName('content.xml');
        $this->zip->addFromString('content.xml', $this->content);
        $this->zip->close();

        copy($this->temporary_template_file, $compiled_file);
    }

}
