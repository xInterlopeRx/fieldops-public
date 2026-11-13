<?php

namespace App\Enums;

enum UsState: string
{
    case Alabama = 'AL';
    case Alaska = 'AK';
    case Arizona = 'AZ';
    case Arkansas = 'AR';
    case California = 'CA';
    case Colorado = 'CO';
    case Connecticut = 'CT';
    case Delaware = 'DE';
    case DistrictOfColumbia = 'DC';
    case Florida = 'FL';
    case Georgia = 'GA';
    case Hawaii = 'HI';
    case Idaho = 'ID';
    case Illinois = 'IL';
    case Indiana = 'IN';
    case Iowa = 'IA';
    case Kansas = 'KS';
    case Kentucky = 'KY';
    case Louisiana = 'LA';
    case Maine = 'ME';
    case Maryland = 'MD';
    case Massachusetts = 'MA';
    case Michigan = 'MI';
    case Minnesota = 'MN';
    case Mississippi = 'MS';
    case Missouri = 'MO';
    case Montana = 'MT';
    case Nebraska = 'NE';
    case Nevada = 'NV';
    case NewHampshire = 'NH';
    case NewJersey = 'NJ';
    case NewMexico = 'NM';
    case NewYork = 'NY';
    case NorthCarolina = 'NC';
    case NorthDakota = 'ND';
    case Ohio = 'OH';
    case Oklahoma = 'OK';
    case Oregon = 'OR';
    case Pennsylvania = 'PA';
    case RhodeIsland = 'RI';
    case SouthCarolina = 'SC';
    case SouthDakota = 'SD';
    case Tennessee = 'TN';
    case Texas = 'TX';
    case Utah = 'UT';
    case Vermont = 'VT';
    case Virginia = 'VA';
    case Washington = 'WA';
    case WestVirginia = 'WV';
    case Wisconsin = 'WI';
    case Wyoming = 'WY';
    case AmericanSamoa = 'AS';
    case Guam = 'GU';
    case NorthernMarianaIslands = 'MP';
    case PuertoRico = 'PR';
    case UsVirginIslands = 'VI';

    public function label(): string
    {
        return match ($this) {
            self::Alabama => 'Alabama',
            self::Alaska => 'Alaska',
            self::Arizona => 'Arizona',
            self::Arkansas => 'Arkansas',
            self::California => 'California',
            self::Colorado => 'Colorado',
            self::Connecticut => 'Connecticut',
            self::Delaware => 'Delaware',
            self::DistrictOfColumbia => 'District of Columbia',
            self::Florida => 'Florida',
            self::Georgia => 'Georgia',
            self::Hawaii => 'Hawaii',
            self::Idaho => 'Idaho',
            self::Illinois => 'Illinois',
            self::Indiana => 'Indiana',
            self::Iowa => 'Iowa',
            self::Kansas => 'Kansas',
            self::Kentucky => 'Kentucky',
            self::Louisiana => 'Louisiana',
            self::Maine => 'Maine',
            self::Maryland => 'Maryland',
            self::Massachusetts => 'Massachusetts',
            self::Michigan => 'Michigan',
            self::Minnesota => 'Minnesota',
            self::Mississippi => 'Mississippi',
            self::Missouri => 'Missouri',
            self::Montana => 'Montana',
            self::Nebraska => 'Nebraska',
            self::Nevada => 'Nevada',
            self::NewHampshire => 'New Hampshire',
            self::NewJersey => 'New Jersey',
            self::NewMexico => 'New Mexico',
            self::NewYork => 'New York',
            self::NorthCarolina => 'North Carolina',
            self::NorthDakota => 'North Dakota',
            self::Ohio => 'Ohio',
            self::Oklahoma => 'Oklahoma',
            self::Oregon => 'Oregon',
            self::Pennsylvania => 'Pennsylvania',
            self::RhodeIsland => 'Rhode Island',
            self::SouthCarolina => 'South Carolina',
            self::SouthDakota => 'South Dakota',
            self::Tennessee => 'Tennessee',
            self::Texas => 'Texas',
            self::Utah => 'Utah',
            self::Vermont => 'Vermont',
            self::Virginia => 'Virginia',
            self::Washington => 'Washington',
            self::WestVirginia => 'West Virginia',
            self::Wisconsin => 'Wisconsin',
            self::Wyoming => 'Wyoming',
            self::AmericanSamoa => 'American Samoa',
            self::Guam => 'Guam',
            self::NorthernMarianaIslands => 'Northern Mariana Islands',
            self::PuertoRico => 'Puerto Rico',
            self::UsVirginIslands => 'U.S. Virgin Islands',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $state): array => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        );
    }
}
