<?php
/*
 * Copyright (C) 2022. Def Studio
 *  Unauthorized copying of this file, via any medium is strictly prohibited
 *  Authors: Fabio Ivona <fabio.ivona@defstudio.it> & Daniele Romeo <danieleromeo@defstudio.it>
 */

/** @noinspection PhpUnhandledExceptionInspection */

namespace DefStudio\TemplateProcessor\Processors;

use DefStudio\TemplateProcessor\Elements\Image;
use DefStudio\TemplateProcessor\Template;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use ZipArchive;

class OdtTemplateProcessor
{

    private string $temporary_template_file;
    private ZipArchive $zip;
    private string $content;
    private string $styles;
    private DOMDocument $manifest;

    /** @var array<Image> */
    private array $images = [];

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
        $this->manifest = new DOMDocument();
        $this->manifest->loadXML($this->zip->getFromName('META-INF/manifest.xml'));
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

    public function insert_image(string $key, \DefStudio\TemplateProcessor\Elements\Image $image)
    {
        if (!$image->valid()) {
            return;
        }

        $this->images[] = $image;

        $document = new DOMDocument();
        $document->loadXML($this->content);

        $dom_frames = $document->getElementsByTagName("frame");
        foreach ($dom_frames as $dom_frame) {
            /** @var DOMElement $dom_frame */
            if ($dom_frame->getAttribute('draw:name') == '${'.$key.'}') {
                /** @var DOMElement $dom_image */
                $dom_image = $dom_frame->getElementsByTagName('image')->item(0);
                $dom_image->setAttribute('xlink:href', "Pictures/$image->uuid");
                $dom_frame->setAttribute('draw:name', $image->uuid);

                if ($image->keep_ratio) {
                    [$width, $height] = getimagesize($image->path);
                    $ratio = $width / $height;

                    $dom_width = Str::of($dom_frame->getAttribute('svg:width'))->remove('cm');
                    $dom_width = floatval((string) $dom_width);

                    $dom_height = $dom_width / $ratio;
                    $dom_frame->setAttribute('svg:height', "{$dom_height}cm");
                }
            }
        }

        $this->content = $document->saveXML();
    }

    public function setValue(string $key, string $value)
    {
        $this->content = $this->replace_key($this->content, $key, $value);
        $this->styles = $this->replace_key($this->styles, $key, $value);
    }

    private function replace_key(string $text, string $key, string $value): string
    {
        $value = Str::of($value)
            ->replace("\r\n", Template::ODT_LINE_BREAK)
            ->replace("\n", Template::ODT_LINE_BREAK)
            ->replace('&amp;', '&')
            ->replace('&', '&amp;');

        $text = Str::of($text)
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

    /** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
    private function applyCloneBlock(string $text, string $blockname, int $clones, array $variableReplacements): string
    {
        $matches = [];

        $regexp = '/';               //Regexp start
        $regexp .= '<[\w>\"=\-: ]*'; //<tag> before section opening
        $regexp .= "\${{$blockname}}";      //section keyword
        $regexp .= '<\/text:[a-z]*>';     //</tag> after section opening
        $regexp .= '(.*)';           //text to repeat
        $regexp .= '<[\w>\"=\-: ]*'; //<tag> before section closing
        $regexp .= "\${\/$blockname}";    //section section keyword
        $regexp .= '<\/text:[a-z]*>';    //</tag> after section opening
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
        $this->clean_orphaned_images();

        $this->zip->deleteName('content.xml');
        $this->zip->addFromString('content.xml', $this->content);

        $this->zip->deleteName('styles.xml');
        $this->zip->addFromString('styles.xml', $this->styles);

        foreach ($this->images as $image) {
            $this->zip->addFile($image->path, "Pictures/$image->uuid");

            $dom_element = $this->manifest->createElement('manifest:file-entry');
            $dom_element->setAttribute('manifest:full-path', "Pictures/$image->uuid");
            $dom_element->setAttribute('manifest:media-type', $image->mime());
            $this->manifest->getElementsByTagName('manifest')->item(0)->appendChild($dom_element);
        }

        $this->zip->deleteName('META-INF/manifest.xml');
        $this->zip->addFromString('META-INF/manifest.xml', $this->manifest->saveXML());

        $this->zip->close();


        copy($this->temporary_template_file, $compiled_file);
    }

    public function clean_orphaned_images(): void
    {
        for ($file_index = 0; $file_index < $this->zip->numFiles; $file_index++) {
            $file_name = $this->zip->getNameIndex($file_index);
            if (!$file_name) {
                continue;
            }
            if (Str::of($file_name)->startsWith("Pictures/")) {
                if (Str::of($this->content)->contains($file_name)) {
                    continue;
                }

                if (Str::of($this->styles)->contains($file_name)) {
                    continue;
                }

                $this->zip->deleteName($file_name);
                $manifest_files = $this->manifest->getElementsByTagName('file-entry');
                foreach ($manifest_files as $manifest_file) {
                    /** @var DOMElement $manifest_file */
                    if ($manifest_file->getAttribute('manifest:full-path') == $file_name) {
                        $manifest_file->remove();
                    }
                }
            }
        }
    }
}
