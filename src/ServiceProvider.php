<?php


namespace DefStudio\TemplateProcessor;


use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->app->bind('template', function () {
            return new Template();
        });
    }

}
