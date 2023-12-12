<?php
/*
 * Copyright (C) 2021. Def Studio
 *  Unauthorized copying of this file, via any medium is strictly prohibited
 *  Authors: Fabio Ivona <fabio.ivona@defstudio.it> & Daniele Romeo <danieleromeo@defstudio.it>
 */

namespace DefStudio\TemplateProcessor;

use DefStudio\TemplateProcessor\Elements\Image;
use DefStudio\TemplateProcessor\Exceptions\TemplateProcessingException;
use DefStudio\TemplateProcessor\Processors\OdtTemplateProcessor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

/**
 * Class Template
 *
 * @package DefStudio\TemplateProcessor
 *
 */
class Template
{
    public const ODT_LINE_BREAK = '<text:line-break/>';
    public const DOCX_LINE_BREAK = '</w:t><w:br/><w:t>';


    protected string $target_extension = 'docx';

    protected string $temporary_directory;

    protected string $template_file;

    protected string $compiled_file;

    /** @var TemplateProcessor|\DefStudio\TemplateProcessor\Processors\OdtTemplateProcessor */
    protected $template_processor;

    public function __construct(string $template_file = '')
    {
        $this->template_file = $template_file;
    }

    public function from($template_file): self
    {
        $this->template_file = $template_file;
        $this->compiled_file = "";
        $this->temporary_directory = "";
        $this->template_processor = null;

        return $this;
    }

    public function compile(array $data): self
    {
        collect($data)->each(function ($content, $key) {
            if (is_array($content)) {
                $this->clone($key, count($content), $content);
                return;
            }

            if ($content instanceof Image) {
                $this->insert_image($key, $content);
                return;
            }

            $this->set($key, $content);
        });

        return $this;
    }

    public function insert_image(string $key, Image $image): self
    {
        $this->template_processor()->insert_image($key, $image);
        return $this;
    }

    public function set(string $key, string|null $value): self
    {
        $this->template_processor()->setValue(
            $key,
            $this->prepare_data_for_template($value)
        );

        return $this;
    }

    public function prepare_data_for_template(string|null $text): string
    {
        if ($this->template_processor() instanceof TemplateProcessor) {
            $text = str($text ?? '')
                ->replace("\r\n", self::DOCX_LINE_BREAK)
                ->replace("\n", self::DOCX_LINE_BREAK)
                ->replace('&amp;', '&')
                ->replace('&', '&amp;');
        }

        return $text ?? '';
    }

    public function remove(string $block_name): self
    {
        $this->template_processor()->deleteBlock($block_name);
        return $this;
    }

    public function clone(string $block_name, int $times = 1, array $variable_replacements = []): self
    {
        $this->template_processor()->cloneBlock(
            blockname: $block_name,
            clones: $times,
            variableReplacements: $variable_replacements
        );
        return $this;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    protected function template_processor(): TemplateProcessor|OdtTemplateProcessor
    {
        if (str($this->template_file)->endsWith('.docx')) {
            return $this->template_processor ??= new TemplateProcessor($this->template_file);
        } else {
            return $this->template_processor ??= new OdtTemplateProcessor($this->template_file);
        }

    }

    public function download(string $downloaded_filename): BinaryFileResponse
    {
        $output_file = $this->temporary_directory()."/temp";

        ob_end_clean();

        if ($this->target_extension == 'pdf') {
            return response()->download($this->to_pdf_file($output_file.".pdf"), $downloaded_filename);
        }

        return response()->download($this->to_docx_file($output_file.".docx"), $downloaded_filename);
    }

    public function store(string $output_file): string
    {
        if ($this->target_extension == 'pdf') {
            return $this->to_pdf_file($output_file);
        }

        return $this->to_docx_file($output_file);
    }

    protected function to_docx_file(string $output_file): string
    {
        $compiled_file = $this->compiled_file();
        File::move($compiled_file, $this->set_extension($output_file, 'docx'));
        return $output_file;
    }

    public function to_pdf($convert_to_pdf = true): self
    {
        if ($convert_to_pdf) {
            $this->target_extension = 'pdf';
        }
        return $this;
    }

    protected function to_pdf_file(string $output_file): string
    {
        $compiled_file = $this->compiled_file();

        $temporary_directory = $this->temporary_directory();

        $process = new Process([
            'lowriter',
            '-env:UserInstallation=file:///tmp/dummy',
            '--convert-to',
            'pdf',
            '--outdir',
            $temporary_directory,
            $compiled_file,
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw TemplateProcessingException::symfony_process_error($process);
        }

        $temporary_result_file = Str::of($temporary_directory)
            ->append(DIRECTORY_SEPARATOR)
            ->append($this->set_extension($this->get_filename($compiled_file), 'pdf'))
            ->replace("/./", '/');

        if (!File::exists($temporary_result_file)) {
            throw TemplateProcessingException::missing_converted_file($temporary_result_file);
        }


        $temporary_result_file = (string) $temporary_result_file;
        $output_file = (string) $this->set_extension($output_file, 'pdf');
        File::move($temporary_result_file, $output_file);

        return $output_file;
    }

    protected function compiled_file(): string
    {
        if (empty($this->compiled_file)) {
            if ($this->template_processor() instanceof OdtTemplateProcessor) {
                $compiled_file = Str::of($this->temporary_directory())
                    ->append(DIRECTORY_SEPARATOR)
                    ->append(Str::uuid())
                    ->append(".odt");
            } else {
                $compiled_file = Str::of($this->temporary_directory())
                    ->append(DIRECTORY_SEPARATOR)
                    ->append(Str::uuid())
                    ->append(".docx");
            }
            $this->template_processor()->saveAs($compiled_file);
            $this->compiled_file = $compiled_file;
        }

        return $this->compiled_file;
    }

    protected function temporary_directory(): string
    {
        if (empty($this->temporary_directory)) {
            $temp_dir = Str::of(sys_get_temp_dir())
                ->append(DIRECTORY_SEPARATOR)
                ->append("LaravelTemplateProcessor")
                ->append(DIRECTORY_SEPARATOR)
                ->append(Str::uuid());

            mkdir($temp_dir, 0777, true);

            $this->temporary_directory = $temp_dir;
        }

        return $this->temporary_directory;
    }

    protected function set_extension(string $file, string $ext): string
    {
        $path = $this->get_path($file);
        $filename_without_ext = $this->get_filename_without_ext($file);

        return Str::of($path)
            ->append(DIRECTORY_SEPARATOR)
            ->append($filename_without_ext)
            ->append('.')
            ->append($ext);
    }

    protected function get_path(string $file): string
    {
        return File::dirname($file);
    }

    protected function get_filename_without_ext(string $file)
    {
        return pathinfo($file)['filename'];
    }

    protected function get_filename(string $file): string
    {
        return File::basename($file);
    }

    protected function get_extension(string $file): string
    {
        return File::extension($file);
    }
}
