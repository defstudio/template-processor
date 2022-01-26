### lowriter installation on docker

```dockerfile
ARG ENABLE_LIBREOFFICE_WRITER=0
RUN if [ ${ENABLE_LIBREOFFICE_WRITER} = 1 ] ; then \
    mkdir -p /usr/share/man/man1 \
    && mkdir -p /.cache/dconf && chmod -R 777 /.cache/dconf \
    && apt-get update \
    && apt-get install -y --no-install-recommends openjdk-11-jre-headless \
    && apt-get install -y --no-install-recommends libreoffice-writer \
    && apt-get install -y --no-install-recommends libreoffice-java-common ;\
fi;
```

### Usage

```php
use DefStudio\TemplateProcessor\Elements\Image;Template::from($dock_template_path)
        ->compile([
            // will replace all instances of ${single_key} with BAR
            'single_key' => 'BAR', 
            
            // and will repeat the block enclosed by ${array_key}[...]${/array_key}
            'array_key' => [
                ['name' => 'paul', 'age' => '36'],  // with the name and age of each element of the array
                ['name' => 'john', 'age' => '24'],  
                ['name' => 'ringo', 'age' => '48'],
                ['name' => 'george', 'age' => '22']
            ],
            
            // will replace an image named ${signature} with the one passed as argument
            'signature' => new Image(path: '/var/www/storage/app/signature.png', keep_ratio: true)) 
        ])  
        
        // Each action can be done as a standalone call
        ->set('single_key', 'Bar')
        ->clone(block_name: 'users', variable_replacements: [
            ['name' => 'paul', 'age' => '36'],
            ['name' => 'ringo', 'age' => '48'],
            ['name' => 'george', 'age' => '22'],
        ])
        ->insert_image('signature', new Image(path: '/var/www/storage/app/signature.png', keep_ratio: true))
        
        //add ->to_pdf() to convert to .pdf, otherwise an .odt file will be built
        ->to_pdf() 
        
        // stores the document in a file
        ->store($output_file)
        
        // or returns a BinaryFileResponse
        ->download($dowloaded_file_name)
```
