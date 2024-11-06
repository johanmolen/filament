<?php

namespace Filament\Commands\FileGenerators\Resources;

use Filament\Clusters\Cluster;
use Filament\Commands\FileGenerators\Resources\Concerns\CanGenerateResourceForms;
use Filament\Commands\FileGenerators\Resources\Concerns\CanGenerateResourceTables;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schema\Schema;
use Filament\Support\Commands\Concerns\CanReadModelSchemas;
use Filament\Support\Commands\FileGenerators\ClassGenerator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Property;

class ResourceClassGenerator extends ClassGenerator
{
    use CanGenerateResourceForms;
    use CanGenerateResourceTables;
    use CanReadModelSchemas;

    protected PhpNamespace $namespace;

    /**
     * @param  class-string<Model>  $modelFqn
     * @param  ?class-string<Cluster>  $clusterFqn
     * @param array<string, array{
     *     class: class-string<Page>,
     *     path: string,
     * }> $pages
     */
    final public function __construct(
        protected string $fqn,
        protected string $modelFqn,
        protected array $pages,
        protected ?string $clusterFqn,
        protected bool $hasViewOperation,
        protected bool $isGenerated,
        protected bool $isSoftDeletable,
        protected bool $isSimple,
    ) {}

    public function getNamespace(): string
    {
        return $this->extractNamespace($this->getFqn());
    }

    /**
     * @return array<string>
     */
    public function getImports(): array
    {
        return [
            Resource::class,
            Schema::class,
            Table::class,
            ...(($this->getModelBasename() === 'Resource') ? [$this->getModelFqn() => 'ResourceModel'] : [$this->getModelFqn()]),
            ...($this->hasCluster() ? (($this->getClusterBasename() === 'Resource') ? [$this->getClusterFqn() => 'ResourceCluster'] : [$this->getClusterFqn()]) : []),
            ...($this->isSoftDeletable() ? [Builder::class, SoftDeletingScope::class] : []),
            ...$this->getPagesImports(),
            ...($this->hasPartialImports() ? [
                ...($this->hasEmbeddedPanelResourceTables()) ? ['Filament\Actions', 'Filament\Tables'] : [],
                ...($this->hasEmbeddedPanelResourceSchemas()) ? [
                    'Filament\Forms',
                    ...($this->hasViewOperation() ? ['Filament\Infolists'] : []),
                ] : [],
            ] : []),
        ];
    }

    public function getBasename(): string
    {
        return class_basename($this->getFqn());
    }

    public function getExtends(): string
    {
        return Resource::class;
    }

    protected function addPropertiesToClass(ClassType $class): void
    {
        $this->addModelPropertyToClass($class);
        $this->addNavigationIconPropertyToClass($class);
        $this->addClusterPropertyToClass($class);
    }

    protected function addMethodsToClass(ClassType $class): void
    {
        $this->addFormMethodToClass($class);
        $this->addInfolistMethodToClass($class);
        $this->addTableMethodToClass($class);
        $this->addGetRelationsMethodToClass($class);
        $this->addGetPagesMethodToClass($class);
        $this->addGetEloquentQueryMethodToClass($class);
    }

    protected function addModelPropertyToClass(ClassType $class): void
    {
        $property = $class->addProperty('model', new Literal("{$this->simplifyFqn($this->getModelFqn())}::class"))
            ->setProtected()
            ->setStatic()
            ->setType('?string');
        $this->configureModelProperty($property);
    }

    protected function configureModelProperty(Property $property): void {}

    protected function addNavigationIconPropertyToClass(ClassType $class): void
    {
        $property = $class->addProperty('navigationIcon', 'heroicon-o-rectangle-stack')
            ->setProtected()
            ->setStatic()
            ->setType('?string');
        $this->configureNavigationIconProperty($property);
    }

    protected function configureNavigationIconProperty(Property $property): void {}

    protected function addClusterPropertyToClass(ClassType $class): void
    {
        if (! $this->hasCluster()) {
            return;
        }

        $property = $class->addProperty('cluster', new Literal("{$this->simplifyFqn($this->clusterFqn)}::class"))
            ->setProtected()
            ->setStatic()
            ->setType('?string');
        $this->configureClusterProperty($property);
    }

    protected function configureClusterProperty(Property $property): void {}

    protected function addFormMethodToClass(ClassType $class): void
    {
        $method = $class->addMethod('form')
            ->setPublic()
            ->setStatic()
            ->setReturnType(Schema::class)
            ->setBody($this->getFormMethodBody());
        $method->addParameter('schema')
            ->setType(Schema::class);

        $this->configureFormMethod($method);
    }

    protected function configureFormMethod(Method $method): void {}

    protected function addInfolistMethodToClass(ClassType $class): void
    {
        if (! $this->hasViewOperation()) {
            return;
        }

        $method = $class->addMethod('infolist')
            ->setPublic()
            ->setStatic()
            ->setReturnType(Schema::class)
            ->setBody(
                <<<'PHP'
                return $schema
                    ->components([
                        //
                    ]);
                PHP
            );
        $method->addParameter('schema')
            ->setType(Schema::class);

        $this->configureInfolistMethod($method);
    }

    protected function configureInfolistMethod(Method $method): void {}

    protected function addTableMethodToClass(ClassType $class): void
    {
        $method = $class->addMethod('table')
            ->setPublic()
            ->setStatic()
            ->setReturnType(Table::class)
            ->setBody($this->getTableMethodBody());
        $method->addParameter('table')
            ->setType(Table::class);

        $this->configureTableMethod($method);
    }

    protected function configureTableMethod(Method $method): void {}

    protected function addGetRelationsMethodToClass(ClassType $class): void
    {
        if ($this->isSimple()) {
            return;
        }

        $method = $class->addMethod('getRelations')
            ->setPublic()
            ->setStatic()
            ->setReturnType('array')
            ->setBody(
                <<<'PHP'
                return [
                    //
                ];
                PHP
            );

        $this->configureGetRelationsMethod($method);
    }

    protected function configureGetRelationsMethod(Method $method): void {}

    protected function addGetPagesMethodToClass(ClassType $class): void
    {
        $pages = array_map(
            fn (array $page, string $routeName): string => (string) new Literal("? => {$this->simplifyFqn($page['class'])}::route(?),", [
                $routeName,
                $page['path'],
            ]),
            $pages = $this->getPages(),
            array_keys($pages),
        );

        $pagesOutput = implode(PHP_EOL . '    ', $pages);

        $method = $class->addMethod('getPages')
            ->setPublic()
            ->setStatic()
            ->setReturnType('array')
            ->setBody(
                <<<PHP
                return [
                    {$pagesOutput}
                ];
                PHP
            );

        $this->configureGetPagesMethod($method);
    }

    protected function configureGetPagesMethod(Method $method): void {}

    protected function addGetEloquentQueryMethodToClass(ClassType $class): void
    {
        if (! $this->isSoftDeletable()) {
            return;
        }

        $method = $class->addMethod('getEloquentQuery')
            ->setPublic()
            ->setStatic()
            ->setReturnType(Builder::class)
            ->setBody(
                <<<PHP
                return parent::getEloquentQuery()
                    ->withoutGlobalScopes([
                        {$this->simplifyFqn(SoftDeletingScope::class)}::class,
                    ]);
                PHP
            );
        $this->configureGetEloquentQueryMethod($method);
    }

    protected function configureGetEloquentQueryMethod(Method $method): void {}

    public function getFqn(): string
    {
        return $this->fqn;
    }

    public function getModelBasename(): string
    {
        return class_basename($this->getModelFqn());
    }

    /**
     * @return class-string<Model>
     */
    public function getModelFqn(): string
    {
        return $this->modelFqn;
    }

    /**
     * @return ?class-string<Cluster>
     */
    public function getClusterFqn(): ?string
    {
        return $this->clusterFqn;
    }

    public function getClusterBasename(): string
    {
        return class_basename($this->getClusterFqn());
    }

    public function hasCluster(): bool
    {
        return filled($this->getClusterFqn());
    }

    /**
     * @return array<string, array{
     *     class: class-string<Page>,
     *     path: string,
     * }>
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    /**
     * @return array<string>
     */
    public function getPagesImports(): array
    {
        if ($this->hasPartialImports()) {
            return [
                (string) str(Arr::first($this->getPages())['class'])->beforeLast('\\'),
            ];
        }

        return Arr::pluck($this->getPages(), 'class');
    }

    public function hasViewOperation(): bool
    {
        return $this->hasViewOperation;
    }

    public function isGenerated(): bool
    {
        return $this->isGenerated;
    }

    public function isSoftDeletable(): bool
    {
        return $this->isSoftDeletable;
    }

    public function isSimple(): bool
    {
        return $this->isSimple;
    }
}
