<?php
// core/Resources/OrganizerResource.php
namespace Resources;

use Models\Organizer;

class OrganizerResource
{
    public static function fromModel(Organizer $organizer): array
    {
        return [
            'name'                    => $organizer->name,
            'slug'                    => $organizer->slug,
            'avatarUrl'               => $organizer->avatarUrl,
            // W języku strony — ten sam kontekst co na profilu organizatora,
            // więc tłumaczenie (i ręczna poprawka) jest jedno dla obu miejsc.
            'bio'                     => \Models\ContentTranslation::text($organizer->bio, 'organizer:' . $organizer->userId),
            'type'                    => $organizer->organizerType,
            'isVerified'              => $organizer->isVerified,
            'tourismRegisterNumber'   => $organizer->tourismRegisterNumber,
            'hasLiabilityInsurance'   => $organizer->safetyLiabilityInsurance,
            'ratingAvg'               => $organizer->ratingAvg,
            'reviewCount'             => $organizer->reviewCount,
            'eventsOrganizedCount'    => $organizer->eventsOrganizedCount,
        ];
    }
}
