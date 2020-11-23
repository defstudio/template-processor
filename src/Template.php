<?php

namespace DefStudio\TemplateProcessor;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Class Template
 * @package DefStudio\TemplateProcessor
 *
 * @noinspection PhpUndefinedClassInspection
 */
class Template
{
    protected string $temporary_directory;

    protected string $template_file;

    protected string $compiled_file;

    public function __construct(string $template_file = '')
    {
        $this->template_file = $template_file;
    }

    public function from($template_file): self
    {

        $this->template_file = $template_file;

        return $this;
    }

    public function to_pdf(string $output_file)
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
            $compiled_file
        ]);

        if (!$process->isSuccessful()) {

        }
    }

    protected function compiled_file(): string
    {
        return $this->compiled_file ??= $this->template_file;
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
