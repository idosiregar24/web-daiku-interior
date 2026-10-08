import { UsernameInput, suggestUsernameFor } from '@/Components/shared/UsernameInput';
import { Button } from '@/Components/ui/button';
import { FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Wand2 } from 'lucide-react';
import { useState } from 'react';
import { type Control, type UseFormSetValue, useWatch } from 'react-hook-form';

export interface IdentityValues {
    name: string;
    username: string;
    email: string;
}

/**
 * Sprint 21 Sub 04 — Username + Email on Buat/Edit User: at least one
 * (both starred while both are empty, Sprint 16), "Buat dari nama" fills
 * the first free username for the name above.
 */
export function UserIdentityFields({
    control,
    setValue,
    userId,
}: {
    control: Control<IdentityValues>;
    setValue: UseFormSetValue<IdentityValues>;
    userId?: number;
}) {
    const [name, username, email] = useWatch({ control, name: ['name', 'username', 'email'] });
    const bothEmpty = username.trim() === '' && email.trim() === '';
    const [suggesting, setSuggesting] = useState(false);

    async function fillFromName() {
        setSuggesting(true);
        try {
            const suggestion = await suggestUsernameFor(name, userId);
            if (suggestion) setValue('username', suggestion, { shouldValidate: true, shouldDirty: true });
        } finally {
            setSuggesting(false);
        }
    }

    return (
        <>
            <FormField
                control={control}
                name="username"
                render={({ field }) => (
                    <FormItem>
                        <div className="flex items-center justify-between gap-2">
                            <FormLabel required={bothEmpty}>Username</FormLabel>
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                disabled={name.trim() === '' || suggesting}
                                onClick={fillFromName}
                            >
                                <Wand2 />
                                Buat dari nama
                            </Button>
                        </div>
                        <FormControl>
                            <UsernameInput
                                value={field.value}
                                onChange={field.onChange}
                                onBlur={field.onBlur}
                                name={field.name}
                                userId={userId}
                                personName={name}
                                placeholder="mis. budisantoso"
                            />
                        </FormControl>
                        <FormDescription>Huruf kecil dan angka saja. Dipakai untuk masuk, selain email.</FormDescription>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <FormField
                control={control}
                name="email"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel required={bothEmpty}>Email</FormLabel>
                        <FormControl>
                            <Input type="email" autoCapitalize="none" placeholder="nama@email.com" {...field} />
                        </FormControl>
                        <FormDescription>Isi username, email, atau keduanya.</FormDescription>
                        <FormMessage />
                    </FormItem>
                )}
            />
        </>
    );
}
