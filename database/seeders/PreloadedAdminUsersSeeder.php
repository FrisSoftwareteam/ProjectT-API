<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PreloadedAdminUsersSeeder extends Seeder
{
    /**
     * Preload administrators without creating or bypassing Microsoft identities.
     */
    public function run(): void
    {
        foreach ($this->emails() as $sourceEmail) {
            $email = Str::lower(trim($sourceEmail));

            $existingUser = AdminUser::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($existingUser) {
                continue;
            }

            [$firstName, $lastName] = $this->namesFromEmail($email);

            AdminUser::query()->create([
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return array{string, string}
     */
    private function namesFromEmail(string $email): array
    {
        $localPart = Str::before($email, '@');

        if ($localPart === 'oluwaseyiadetola') {
            return ['Oluwaseyi', 'Adetola'];
        }

        $parts = preg_split('/[._]+/', $localPart, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = Str::title(array_shift($parts) ?? $localPart);
        $lastName = Str::title(implode(' ', $parts));

        return [$firstName, $lastName !== '' ? $lastName : 'Unknown'];
    }

    /**
     * @return list<string>
     */
    private function emails(): array
    {
        return [
            'yaya.lawal@firstregistrarsnigeria.com',
            'amidu.akinyemi@firstregistrarsnigeria.com',
            'oluwaseyiadetola@firstregistrarsnigeria.com',
            'olubunmi.oguntoye@firstregistrarsnigeria.com',
            'olukorede.titilolu@firstregistrarsnigeria.com',
            'omowunmi.senkoya@firstregistrarsnigeria.com',
            'modupeola.ajigbotafe@firstregistrarsnigeria.com',
            'olusegun.adeyemi@firstregistrarsnigeria.com',
            'henry.iyinbor@firstregistrarsnigeria.com',
            'emmanuel.effiong@firstregistrarsnigeria.com',
            'julian.nworisa@firstregistrarsnigeria.com',
            'motolani.abbayomi@firstregistrarsnigeria.com',
            'fatimah.sulaimon@firstregistrarsnigeria.com',
            'joke.adeyemi@firstregistrarsnigeria.com',
            'adetoro.johnson@firstregistrarsnigeria.com',
            'adewale.adepoju@firstregistrarsnigeria.com',
            'oluseyi.olumolu@firstregistrarsnigeria.com',
            'kemi.michael-noah@firstregistrarsnigeria.com',
            'chinedu.ndubuiro@firstregistrarsnigeria.com',
            'tolu.medaiyedu@firstregistrarsnigeria.com',
            'olufemi.oyelami@firstregistrarsnigeria.com',
            'oluwajumoke.kushimo@firstregistrarsnigeria.com',
            'ifeoma.nkwocha@firstregistrarsnigeria.com',
            'funlola.awope@firstregistrarsnigeria.com',
            'olakunle.oke@firstregistrarsnigeria.com',
            'olayinka.ojeniyi@firstregistrarsnigeria.com',
            'beatrice.akinrinade@firstregistrarsnigeria.com',
            'stella.anumudu@firstregistrarsnigeria.com',
            'monisola.ponle@firstregistrarsnigeria.com',
            'stanley.izuobi@firstregistrarsnigeria.com',
            'colin.decorce@firstregistrarsnigeria.com',
            'pelumi.akinwole@firstregistrarsnigeria.com',
            'ifeanyi.ayodeji@firstregistrarsnigeria.com',
            'aaron.adeoye@firstregistrarsnigeria.com',
            'damilare.oje@firstregistrarsnigeria.com',
            'john.adebayo@firstregistrarsnigeria.com',
            'chioma.okolie@firstregistrarsnigeria.com',
            'yakubu.alawode@firstregistrarsnigeria.com',
            'rebecca.okunoye@firstregistrarsnigeria.com',
            'adewale.babatunde@firstregistrarsnigeria.com',
            'hanah.itaniyi-olagoke@firstregistrarsnigeria.com',
            'kehinde.balogun@firstregistrarsnigeria.com',
            'adeola.daramola@firstregistrarsnigeria.com',
            'ebenezer.olayiwola@firstregistrarsnigeria.com',
            'nike.kogbe@firstregistrarsnigeria.com',
            'oluwafemi.komolafe@firstregistrarsnigeria.com',
            'olubunmi.odelola@firstregistrarsnigeria.com',
            'marcus.ogu@firstregistrarsnigeria.com',
            'ngozi.mbakwe@firstregistrarsnigeria.com',
            'azeez.bamidele@firstregistrarsnigeria.com',
            'samson.emoruwa@firstregistrarsnigeria.com',
            'oluwakemi.ajiboye@firstregistrarsnigeria.com',
            'michael.ogundairo@firstregistrarsnigeria.com',
            'timothy.olugbemi@firstregistrarsnigeria.com',
            'olufisayo.olugbemi@firstregistrarsnigeria.com',
            'omoduni.bolorunduro@firstregistrarsnigeria.com',
            'olugbenga.ariyo@firstregistrarsnigeria.com',
            'chukwuemeka.ugoezi@firstregistrarsnigeria.com',
            'labake.ajibola@firstregistrarsnigeria.com',
            'omotayo.olude@firstregistrarsnigeria.com',
            'oluwamuyiwa.ola-samuel@firstregistrarsnigeria.com',
            'wale.olaiya@firstregistrarsnigeria.com',
            'agnes.deele@firstregistrarsnigeria.com',
            'sake.k.vini@firstregistrarsnigeria.com',
            'anthony.i.bassey@firstregistrarsnigeria.com',
            'oludele.gbenro@firstregistrarsnigeria.com',
            'abiola.taiwo@firstregistrarsnigeria.com',
            'bukola.oyeyinka@firstregistrarsnigeria.com',
            'olumide.olugbemi@firstregistrarsnigeria.com',
            'kikelomo.umbakogo@firstregistrarsnigeria.com',
            'adebanke.olabode@firstregistrarsnigeria.com',
            'feyishade.adekunle@firstregistrarsnigeria.com',
            'adesola.oluwasegun@firstregistrarsnigeria.com',
            'adepeju.adebanjo@firstregistrarsnigeria.com',
            'adebisi.olanrewaju@firstregistrarsnigeria.com',
            'francis.oranye@firstregistrarsnigeria.com',
            'saheed.olaadua@firstregistrarsnigeria.com',
            'doris.ejemuta@firstregistrarsnigeria.com',
            'ayannubi.tope@firstregistrarsnigeria.com',
            'sammy.iwelu@firstregistrarsnigeria.com',
            'busayo.ogundiran@firstregistrarsnigeria.com',
        ];
    }
}
