<?php
/*
 * Copyright (C) 2022. Def Studio
 *  Unauthorized copying of this file, via any medium is strictly prohibited
 *  Authors: Fabio Ivona <fabio.ivona@defstudio.it> & Daniele Romeo <danieleromeo@defstudio.it>
 */

/** @noinspection PhpUnhandledExceptionInspection */

namespace DefStudio\TemplateProcessor\Processors;

use DefStudio\TemplateProcessor\Template;
use File;
use Image;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use ZipArchive;
use function str;

class OdtTemplateProcessor
{

    private string $temporary_template_file;
    private ZipArchive $zip;
    private string $content;
    private string $styles;
    private array $images = [

    ];

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

        $this->content = $this->cleanup_text($this->zip->getFromName('content.xml'));
        $this->styles = $this->cleanup_text($this->zip->getFromName('styles.xml'));

    }

    private function cleanup_text(string $text): string
    {
        $characters = str_split($text);

        $clean_text = '';
        $current_variable = '';
        $status = 'outside_variable';

        foreach ($characters as $char) {
            switch ($status) {
                case 'outside_variable':
                    $clean_text .= $char;
                    if ($char != '$') {
                        break;
                    }

                    $status = 'maybe_started_variable';
                    break;
                case 'maybe_started_variable':
                    $clean_text .= $char;

                    if ($char != '{') {
                        break;
                    }

                    $status = 'inside_variable';
                    $current_variable = '';
                    break;
                case 'inside_variable':
                    $current_variable .= $char;
                    if ($char == '}') {
                        $status = 'outside_variable';
                        $current_variable = preg_replace('/<[^>]*>/', '', $current_variable);
                        $clean_text .= $current_variable;
                    }
                    break;
            }
        }


        return $clean_text;
    }

    public function insert_image(string $key, Image $image)
    {
        if(!$image->valid()){
            return;
        }

        $this->images[$image->uuid] = $image->path;

        $image_name = "Image " . count($this->images);

        $image = <<<IMG
            <draw:frame draw:style-name="fr1" draw:name="$image_name" text:anchor-type="char" svg:x="{$image->position_x}cm" svg:y="{$image->position_y}cm" svg:width="{$image->width}cm" svg:height="{$image->height}cm" draw:z-index="0">
                <draw:image xlink:href="Pictures/$image->uuid" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad" draw:mime-type="{$image->mime()}"/>
            </draw:frame>
        IMG;


        $this->setValue($key, "");
    }

    public function setValue(string $key, string $value)
    {
        $this->content = $this->replace_key($this->content, $key, $value);
        $this->styles = $this->replace_key($this->styles, $key, $value);
    }

    private function replace_key(string $text, string $key, string $value): string
    {
        $value = str($value)
            ->replace("\r\n", Template::ODT_LINE_BREAK)
            ->replace("\n", Template::ODT_LINE_BREAK)
            ->replace('&amp;', '&')
            ->replace('&', '&amp;');

        $text = str($text)
            ->replace('${'.$key.'}', $value);

        return $text;
    }

    public function deleteBlock(string $blockname)
    {
        $this->cloneBlock($blockname, 0);
    }

    public function cloneBlock(string $blockname, int $clones = 1, array $variableReplacements = [])
    {
        $this->content = $this->applyCloneBlock($this->content, $blockname, $clones, $variableReplacements);
        $this->styles = $this->applyCloneBlock($this->styles, $blockname, $clones, $variableReplacements);
    }

    private function applyCloneBlock(string $text, string $blockname, int $clones, array $variableReplacements): string
    {
        $matches = [];

        $regexp = '/';               //Regexp start
        $regexp .= '<[\w>\"=\-: ]*'; //<tag> before section opening
        $regexp .= "{{$blockname}}";      //section keyword
        $regexp .= '<\/text:p>';     //</tag> after section opening
        $regexp .= '(.*)';           //text to repeat
        $regexp .= '<[\w>\"=\-: ]*'; //<tag> before section closing
        $regexp .= "{\/$blockname}";    //section section keyword
        $regexp .= '<\/text:p>';    //</tag> after section opening
        $regexp .= '/m';            //Regexp end (m = multiline)

        preg_match($regexp, $text, $matches);
        $text_to_repeat = $matches[1] ?? '';

        $text_to_replace = "";
        for ($copy = 0; $copy < $clones; $copy++) {
            $text_to_copy = $text_to_repeat;

            $variables_to_replace = array_shift($variableReplacements) ?? [];

            foreach ($variables_to_replace as $key => $value) {
                $text_to_copy = $this->replace_key($text_to_copy, $key, $value);
            }

            $text_to_replace .= $text_to_copy;
        }

        return preg_replace($regexp, $text_to_replace, $text);
    }

    public function saveAs($compiled_file)
    {
        $this->zip->deleteName('content.xml');
        $this->zip->addFromString('content.xml', $this->content);
        $this->zip->deleteName('styles.xml');
        $this->zip->addFromString('styles.xml', $this->styles);
        $this->add_images();
        $this->zip->close();

        copy($this->temporary_template_file, $compiled_file);
    }

    public function add_images(): void
    {
        if(empty($this->images)){
            return;
        }

        foreach ($this->images as $uuid => $path){
            $this->zip->addFile($path, "Pictures/$uuid");
        }
    }

}
