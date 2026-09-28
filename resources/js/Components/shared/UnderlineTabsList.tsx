import { TabsList } from '@/Components/ui/tabs';
import { cn } from '@/lib/utils';
import type { ComponentProps } from 'react';

/**
 * Page-level section tabs (Project detail, Master Data): full-width
 * underline bar instead of the pill switcher. Scrolls sideways on narrow
 * screens; the bottom padding keeps the active underline inside the
 * scroll box so it isn't clipped. Children are ordinary <TabsTrigger>s.
 */
export function UnderlineTabsList({ className, ...props }: ComponentProps<typeof TabsList>) {
    return (
        <TabsList
            variant="line"
            className={cn(
                'scrollbar-thin h-auto! w-full justify-start overflow-x-auto overflow-y-hidden border-b border-border pb-1.5 *:flex-none *:px-3',
                className,
            )}
            {...props}
        />
    );
}
