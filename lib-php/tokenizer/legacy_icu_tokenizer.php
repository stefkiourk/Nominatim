<?php

namespace Nominatim;

class Tokenizer
{
    private $oDB;

    private $oNormalizer;
    private $oTransliterator;
    private $aCountryRestriction;

    public function __construct(&$oDB)
    {
        $this->oDB =& $oDB;
        $this->oNormalizer = \Transliterator::createFromRules(CONST_Term_Normalization_Rules);
        $this->oTransliterator = \Transliterator::createFromRules(CONST_Transliteration);
    }

    public function checkStatus()
    {
        $sSQL = 'SELECT word_id FROM word limit 1';
        $iWordID = $this->oDB->getOne($sSQL);
        if ($iWordID === false) {
            throw new \Exception('Query failed', 703);
        }
        if (!$iWordID) {
            throw new \Exception('No value', 704);
        }
    }


    public function setCountryRestriction($aCountries)
    {
        $this->aCountryRestriction = $aCountries;
    }


    public function normalizeString($sTerm)
    {
        if ($this->oNormalizer === null) {
            return $sTerm;
        }

        return $this->oNormalizer->transliterate($sTerm);
    }

    private function makeStandardWord($sTerm)
    {
        return trim($this->oTransliterator->transliterate(' '.$sTerm.' '));
    }


    public function tokensForSpecialTerm($sTerm)
    {
        $aResults = array();

        $sSQL = "SELECT word_id, info->>'class' as class, info->>'type' as type ";
        $sSQL .= '   FROM word WHERE word_token = :term and type = \'S\'';

        Debug::printVar('Term', $sTerm);
        Debug::printSQL($sSQL);
        $aSearchWords = $this->oDB->getAll($sSQL, array(':term' => $this->makeStandardWord($sTerm)));

        Debug::printVar('Results', $aSearchWords);

        foreach ($aSearchWords as $aSearchTerm) {
            $aResults[] = new \Nominatim\Token\SpecialTerm(
                $aSearchTerm['word_id'],
                $aSearchTerm['class'],
                $aSearchTerm['type'],
                \Nominatim\Operator::TYPE
            );
        }

        Debug::printVar('Special term tokens', $aResults);

        return $aResults;
    }


    public function extractTokensFromPhrases(&$aPhrases)
    {
        $sNormQuery = '';
        $aWordLists = array();
        $aTokens = array();
        foreach ($aPhrases as $iPhrase => $oPhrase) {
            $sNormQuery .= ','.$this->normalizeString($oPhrase->getPhrase());
            $sPhrase = $this->makeStandardWord($oPhrase->getPhrase());
            Debug::printVar('Phrase', $sPhrase);
            if (strlen($sPhrase) > 0) {
                $aWords = explode(' ', $sPhrase);
                Tokenizer::addTokens($aTokens, $aWords);
                $aWordLists[] = $aWords;
            } else {
                $aWordLists[] = array();
            }
        }

        Debug::printVar('Tokens', $aTokens);
        Debug::printVar('WordLists', $aWordLists);

        $oValidTokens = $this->computeValidTokens($aTokens, $sNormQuery);

        foreach ($aPhrases as $iPhrase => $oPhrase) {
            $oPhrase->computeWordSets($aWordLists[$iPhrase], $oValidTokens);
        }

        return $oValidTokens;
    }


    private function computeValidTokens($aTokens, $sNormQuery)
    {
        $oValidTokens = new TokenList();

        if (!empty($aTokens)) {
            $this->addTokensFromDB($oValidTokens, $aTokens, $sNormQuery);

            // Try more interpretations for Tokens that could not be matched.
            foreach ($aTokens as $sToken) {
                if ($sToken[0] != ' ' && !$oValidTokens->contains($sToken)) {
                    if (preg_match('/^([0-9]{5}) [0-9]{4}$/', $sToken, $aData)) {
                        // US ZIP+4 codes - merge in the 5-digit ZIP code
                        $oValidTokens->addToken(
                            $sToken,
                            new Token\Postcode(null, $aData[1], 'us')
                        );
                    } elseif (preg_match('/^[0-9]+$/', $sToken)) {
                        // Unknown single word token with a number.
                        // Assume it is a house number.
                        $oValidTokens->addToken(
                            $sToken,
                            new Token\HouseNumber(null, trim($sToken))
                        );
                    }
                }
            }
        }

        return $oValidTokens;
    }


    private function addTokensFromDB(&$oValidTokens, $aTokens, $sNormQuery)
    {
        // Check which tokens we have, get the ID numbers
        $sSQL = 'SELECT word_id, word_token, type, word,';
        $sSQL .= "      info->>'op' as operator,";
        $sSQL .= "      info->>'class' as class, info->>'type' as ctype,";
        $sSQL .= "      info->>'count' as count";
        $sSQL .= ' FROM word WHERE word_token in (';
        $sSQL .= join(',', $this->oDB->getDBQuotedList($aTokens)).')';

        Debug::printSQL($sSQL);

        $aDBWords = $this->oDB->getAll($sSQL, null, 'Could not get word tokens.');

        foreach ($aDBWords as $aWord) {
            $iId = (int) $aWord['word_id'];
            $sTok = $aWord['word_token'];

            switch ($aWord['type']) {
                case 'C':  // country name tokens
                    if ($aWord['word'] !== null
                        && (!$this->aCountryRestriction
                            || in_array($aWord['word'], $this->aCountryRestriction))
                    ) {
                        $oValidTokens->addToken(
                            $sTok,
                            new Token\Country($iId, $aWord['word'])
                        );
                    }
                    break;
                case 'H':  // house number tokens
                    $oValidTokens->addToken($sTok, new Token\HouseNumber($iId, $aWord['word_token']));
                    break;
                case 'P':  // postcode tokens
                    // Postcodes are not normalized, so they may have content
                    // that makes SQL injection possible. Reject postcodes
                    // that would need special escaping.
                    if ($aWord['word'] !== null
                        && pg_escape_string($aWord['word']) == $aWord['word']
                    ) {
                        $sNormPostcode = $this->normalizeString($aWord['word']);
                        if (strpos($sNormQuery, $sNormPostcode) !== false) {
                            $oValidTokens->addToken(
                                $sTok,
                                new Token\Postcode($iId, $aWord['word'], null)
                            );
                        }
                    }
                    break;
                case 'S':  // tokens for classification terms (special phrases)
                    if ($aWord['class'] !== null && $aWord['ctype'] !== null) {
                        $oValidTokens->addToken($sTok, new Token\SpecialTerm(
                            $iId,
                            $aWord['class'],
                            $aWord['ctype'],
                            (isset($aWord['operator'])) ? Operator::NEAR : Operator::NONE
                        ));
                    }
                    break;
                case 'W': // full-word tokens
                    $oValidTokens->addToken($sTok, new Token\Word(
                        $iId,
                        (int) $aWord['count'],
                        substr_count($aWord['word_token'], ' ')
                    ));
                    break;
                case 'w':  // partial word terms
                    $oValidTokens->addToken($sTok, new Token\Partial(
                        $iId,
                        $aWord['word_token'],
                        (int) $aWord['count']
                    ));
                    break;
                default:
                    break;
            }
        }
    }


    /**
     * Add the tokens from this phrase to the given list of tokens.
     *
     * @param string[] $aTokens List of tokens to append.
     *
     * @return void
     */
    private static function addTokens(&$aTokens, $aWords)
    {
        $iNumWords = count($aWords);

        for ($i = 0; $i < $iNumWords; $i++) {
            $sPhrase = $aWords[$i];
            $aTokens[$sPhrase] = $sPhrase;

            for ($j = $i + 1; $j < $iNumWords; $j++) {
                $sPhrase .= ' '.$aWords[$j];
                $aTokens[$sPhrase] = $sPhrase;
            }
        }
    }
}