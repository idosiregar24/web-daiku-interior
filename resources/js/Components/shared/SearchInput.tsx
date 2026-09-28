import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';
import type { ComponentProps } from 'react';

/**
 * <Input> with a leading search icon, for list-page filter toolbars.
 * `className` sizes the wrapper (e.g. "sm:max-w-xs"); every other prop
 * goes straight to the input, so value/onChange/onKeyDown work as usual.
 */
export function SearchInput({ className, ...props }: ComponentProps<typeof Input>) {
    return (
        <div className={cn('relative w-full', className)}>
            <Search
                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden
            />
            <Input {...props} className="pl-8" />
        </div>
    );
}
