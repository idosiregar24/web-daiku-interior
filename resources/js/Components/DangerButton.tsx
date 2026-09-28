import { ButtonHTMLAttributes } from 'react';

export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={
                `inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-transparent bg-destructive/10 px-4 text-sm font-medium text-destructive transition-colors hover:bg-destructive/20 focus:outline-none focus-visible:ring-3 focus-visible:ring-destructive/20 ${
                    disabled && 'opacity-50'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
