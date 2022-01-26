<?php
/*
 * Copyright (C) 2022. Def Studio
 *  Unauthorized copying of this file, via any medium is strictly prohibited
 *  Authors: Fabio Ivona <fabio.ivona@defstudio.it> & Daniele Romeo <danieleromeo@defstudio.it>
 */

namespace DefStudio\TemplateProcessor\Elements;

class Image
{
    private const ALLOWED_IMAGE_TYPES = ['image/png', 'image/jpg'];

    public readonly string $uuid;

    public function __construct(
        public readonly string $path,
        public readonly float $position_x,
        public readonly float $position_y,
        public readonly float $width,
        public readonly float $height,
    ) {
        $this->uuid = Str::of(Str::uuid())
            ->append('.')
            ->append(Str::of($this->path)->afterLast('.'));
    }

    public function mime(): string
    {
        return File::mimeType($this->path);
    }

    public function valid(): bool
    {
        if (!File::exists($this->path)) {
            return false;
        }

        if (!in_array(strtolower(File::mimeType($this->path)), self::ALLOWED_IMAGE_TYPES)) {
            return false;
        }

        return true;
    }
}
