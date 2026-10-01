import { Button } from '@/Components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

interface SectionLinkProps {
    href: string;
    children?: string;
}

/** "Lihat semua →" in a SectionCard header — same shape as the Executive Dashboard's detail links. */
export function SectionLink({ href, children = 'Lihat semua' }: SectionLinkProps) {
    return (
        <Button variant="ghost" size="sm" asChild>
            <Link href={href}>
                {children}
                <ArrowRight className="size-3.5" />
            </Link>
        </Button>
    );
}
