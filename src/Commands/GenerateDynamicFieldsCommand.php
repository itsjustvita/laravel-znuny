<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateDynamicFieldsCommand extends Command
{
    protected $signature = 'znuny:generate-fields
        {--output= : Absolute path to write the generated class (defaults to app_path("Znuny/DynamicFields.php"))}';

    protected $description = 'Generate a typed DynamicFields helper class from config/znuny.php';

    public function handle(): int
    {
        $fields = (array) config('znuny.dynamic_fields', []);

        if ($fields === []) {
            $this->warn('No dynamic fields configured in config/znuny.php. Nothing to generate.');

            return self::SUCCESS;
        }

        $output = $this->option('output');
        $target = is_string($output) && $output !== '' ? $output : app_path('Znuny/DynamicFields.php');
        @mkdir(dirname($target), 0777, true);

        $stub = file_get_contents(__DIR__.'/../../stubs/DynamicFields.stub');

        if ($stub === false) {
            $this->error('The stub for the DynamicFields helper could not be read.');

            return self::FAILURE;
        }

        $methods = $this->buildMethods($fields);

        $contents = str_replace('{{ methods }}', $methods, $stub);

        file_put_contents($target, $contents);

        $this->info("Wrote DynamicFields helper to {$target}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function buildMethods(array $fields): string
    {
        $methods = [];

        foreach ($fields as $name => $spec) {
            $methodName = Str::camel($name);
            $type = $this->phpTypeFor((string) ($spec['type'] ?? 'string'));

            $methods[] = <<<PHP
    public function {$methodName}({$type} \$value): self
    {
        \$this->fields['{$name}'] = \$value;
        return \$this;
    }
PHP;
        }

        return implode("\n\n", $methods);
    }

    private function phpTypeFor(string $type): string
    {
        return match ($type) {
            'integer' => 'int',
            'boolean' => 'bool',
            'float' => 'float',
            'array' => 'array',
            default => 'string',
        };
    }
}
