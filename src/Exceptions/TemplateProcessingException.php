<?php


namespace DefStudio\TemplateProcessor\Exceptions;


use Exception;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class TemplateProcessingException extends Exception
{
    public static function symfony_process_error(Process $process)
    {
        return new self(Str::of("Symfony Process Error:")->append(" ")->append($process->getErrorOutput()));
    }

    public static function missing_converted_file(string $missing_file)
    {
        return new self(Str::of("Converted file missing:")->append(" ")->append($missing_file));
    }

}
