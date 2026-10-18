<?php

declare(strict_types=1);

namespace J87NL\Cms;

enum CmsPermission: string
{
    case PagesEdit = 'cms.pages.edit';
    case PagesPublish = 'cms.pages.publish';
    case Menus = 'cms.menus';
    case Media = 'cms.media';

    public function label(): string
    {
        return __(sprintf('cms::permissions.%s', $this->value));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
