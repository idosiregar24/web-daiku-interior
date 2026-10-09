<?php

namespace App\Support\CompanyProfile;

use App\Enums\ProjectType;

/** One service of the company profile, as ServiceCatalog defines it. */
final readonly class ServiceEntry
{
    /**
     * @param  ProjectType  $type  the type its `service_pages` row is keyed by
     * @param  list<ProjectType>  $types  every project type the page covers (portfolio "sejenis")
     * @param  string  $keyword  what people search for, without the city ("Interior Cafe")
     */
    public function __construct(
        public ProjectType $type,
        public array $types,
        public string $slug,
        public string $name,
        public string $keyword,
    ) {}

    public function url(): string
    {
        return route('site.services.show', $this->slug);
    }

    public function covers(ProjectType $type): bool
    {
        return in_array($type, $this->types, true);
    }
}
