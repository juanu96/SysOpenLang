<?php
namespace SysOpenLang\Modules;

defined( 'ABSPATH' ) || exit;

final class Language_Catalog {
	public static function all() {
		$rows = array(
			'en|English|English|en_US|🇺🇸|ltr', 'es|Spanish|Español|es_ES|🇪🇸|ltr', 'de|German|Deutsch|de_DE|🇩🇪|ltr',
			'fr|French|Français|fr_FR|🇫🇷|ltr', 'it|Italian|Italiano|it_IT|🇮🇹|ltr', 'pt-br|Portuguese (Brazil)|Português do Brasil|pt_BR|🇧🇷|ltr',
			'pt-pt|Portuguese (Portugal)|Português|pt_PT|🇵🇹|ltr', 'nl|Dutch|Nederlands|nl_NL|🇳🇱|ltr', 'pl|Polish|Polski|pl_PL|🇵🇱|ltr',
			'ru|Russian|Русский|ru_RU|🇷🇺|ltr', 'uk|Ukrainian|Українська|uk|🇺🇦|ltr', 'cs|Czech|Čeština|cs_CZ|🇨🇿|ltr',
			'sk|Slovak|Slovenčina|sk_SK|🇸🇰|ltr', 'sl|Slovenian|Slovenščina|sl_SI|🇸🇮|ltr', 'hr|Croatian|Hrvatski|hr|🇭🇷|ltr',
			'sr|Serbian|Српски|sr_RS|🇷🇸|ltr', 'bs|Bosnian|Bosanski|bs_BA|🇧🇦|ltr', 'bg|Bulgarian|Български|bg_BG|🇧🇬|ltr',
			'ro|Romanian|Română|ro_RO|🇷🇴|ltr', 'hu|Hungarian|Magyar|hu_HU|🇭🇺|ltr', 'el|Greek|Ελληνικά|el|🇬🇷|ltr',
			'tr|Turkish|Türkçe|tr_TR|🇹🇷|ltr', 'sv|Swedish|Svenska|sv_SE|🇸🇪|ltr', 'da|Danish|Dansk|da_DK|🇩🇰|ltr',
			'no|Norwegian|Norsk bokmål|nb_NO|🇳🇴|ltr', 'fi|Finnish|Suomi|fi|🇫🇮|ltr', 'is|Icelandic|Íslenska|is_IS|🇮🇸|ltr',
			'et|Estonian|Eesti|et|🇪🇪|ltr', 'lv|Latvian|Latviešu|lv|🇱🇻|ltr', 'lt|Lithuanian|Lietuvių|lt_LT|🇱🇹|ltr',
			'ga|Irish|Gaeilge|ga|🇮🇪|ltr', 'cy|Welsh|Cymraeg|cy|🏴|ltr', 'ca|Catalan|Català|ca|🏳️|ltr',
			'eu|Basque|Euskara|eu|🏳️|ltr', 'gl|Galician|Galego|gl_ES|🏳️|ltr', 'sq|Albanian|Shqip|sq|🇦🇱|ltr',
			'mk|Macedonian|Македонски|mk_MK|🇲🇰|ltr', 'hy|Armenian|Հայերեն|hy|🇦🇲|ltr', 'ka|Georgian|ქართული|ka_GE|🇬🇪|ltr',
			'az|Azerbaijani|Azərbaycanca|az|🇦🇿|ltr', 'kk|Kazakh|Қазақша|kk|🇰🇿|ltr', 'uz|Uzbek|O‘zbekcha|uz_UZ|🇺🇿|ltr',
			'ar|Arabic|العربية|ar|🇸🇦|rtl', 'he|Hebrew|עברית|he_IL|🇮🇱|rtl', 'fa|Persian|فارسی|fa_IR|🇮🇷|rtl',
			'ur|Urdu|اردو|ur|🇵🇰|rtl', 'ku|Kurdish|Kurdî|ku|🏳️|ltr', 'hi|Hindi|हिन्दी|hi_IN|🇮🇳|ltr',
			'bn|Bengali|বাংলা|bn_BD|🇧🇩|ltr', 'pa|Punjabi|ਪੰਜਾਬੀ|pa_IN|🇮🇳|ltr', 'ta|Tamil|தமிழ்|ta_IN|🇮🇳|ltr',
			'ne|Nepali|नेपाली|ne_NP|🇳🇵|ltr', 'th|Thai|ไทย|th|🇹🇭|ltr', 'vi|Vietnamese|Tiếng Việt|vi|🇻🇳|ltr',
			'id|Indonesian|Bahasa Indonesia|id_ID|🇮🇩|ltr', 'ms|Malay|Bahasa Melayu|ms_MY|🇲🇾|ltr', 'tl|Filipino|Filipino|tl|🇵🇭|ltr',
			'zh-hans|Chinese (Simplified)|简体中文|zh_CN|🇨🇳|ltr', 'zh-hant|Chinese (Traditional)|繁體中文|zh_TW|🇹🇼|ltr',
			'ja|Japanese|日本語|ja|🇯🇵|ltr', 'ko|Korean|한국어|ko_KR|🇰🇷|ltr', 'eo|Esperanto|Esperanto|eo|🌐|ltr',
		);
		$languages = array();
		foreach ( $rows as $row ) {
			list( $code, $name, $native_name, $locale, $flag, $direction ) = explode( '|', $row );
			$languages[ $code ] = compact( 'name', 'native_name', 'locale', 'flag', 'direction' );
		}
		return apply_filters( 'openlingua_language_catalog', $languages );
	}

	public static function merged() {
		return array_replace( self::all(), (array) get_option( 'openlingua_custom_languages', array() ) );
	}
}

