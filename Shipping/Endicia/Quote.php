<?php
namespace SparkLib\Shipping\Endicia;

use SparkLib\Shipping\Endicia,
    SparkLib\Shipping\Address;

use SparkLib\Xml\Builder;

/**
 * Class to provide quoting from Endicia.
 *
 * Set yourself up like this:
 *
 * $quoter = new Quote;
 * $quoter->from_address = Address $from_address;             //see SparkLib\Shipping\Address
 * $quoter->to_address   = Address $to_address;
 * $quoter->dimensions  = array( $length, $width, $height ); //decimals, in inches. rounded to 3 decimal places.
 * $quoter->weight       = $weight;                           //decimals, in ounces
 *
 * Then pull one of these:
 *
 * $rates = $quoter->quote();
 *
 * And you'll find your quotes to be similar like this:
 *
 * $rates = Array(
 *            [0] => Array(
 *                    [MailClass] => First
 *                    [MailService] => First-Class Mail
 *                    [Postage] => 1.69
 *                )
 *            [1] => Array( ... )
 *            ...
 *          )
 *
 * @author robacarp <robert.carpenter@sparkfun.com>
 */
class Quote extends Endicia {
  public $to_address, $from_address, $dimensions, $weight;

  /**
   * Run a quote query to the endicia servers.
   *
   * @return array shipping quotes
   */
  public function quote(){
    if ( ! $this->from_address instanceof Address)
      throw new \LogicException("From address must be a SparkLib\Shipping\Address. Set Quote#from_address before fetching quotes");
    if ( ! $this->to_address instanceof Address)
      throw new \LogicException("From address must be a SparkLib\Shipping\Address. Set Quote#to_address before fetching quotes");
    if ($this->dimensions === null || ! is_array($this->dimensions) || count($this->dimensions) != 3)
      throw new \LogicException('Dimensions must be set to 3 slot array before quoting');


    $this->request_type = 'CalculatePostageRatesXML';
    $this->post_prefix  = 'postageRatesRequestXML';
    $this->xml          = $this->fetchQuoteXML($this->from_address, $this->to_address, $this->dimensions, $this->weight);

    $this->request();

    $this->parse_response();
    $this->check_status();

    $this->buildQuotes();

    return $this->rates;
  }

  /**
   * Build out the XML required to quote a delivery.
   *
   * Broken out into its own method so that xml can be easily emailed to Endicia when
   * they break something we do.
   *
   * @param Address $from the starting address
   * @param Address $to   the destination address
   * @param array   $dimensions array($length, $width, $height) of the package
   *
   * @return string xml to be sent to Endicia servers
   */
  public function fetchQuoteXML(Address $from, Address $to, array $dimensions, $weight){
    $international = ! $to->domestic;

    // domestic postal codes can only be 5 digits
    $postal_code = $international ? $to->postal_code
                                  : substr($to->postal_code, 0, 5);

    $b = new Builder();
    $b->PostageRatesRequest
      ->nest(
        $this->authXML( $b )
        ->MailClass( $international ? 'International' : 'Domestic' )
        ->WeightOz( $weight )
        ->MailpieceShape('Parcel')
         ->MailpieceDimensions
           ->nest( $b->child()
             // Undocumented: Endicia can't handle dimensions with more than 3 decimal places. :|
             ->Length( number_format( $dimensions[0], 3) )
             ->Width(  number_format( $dimensions[1], 3) )
             ->Height( number_format( $dimensions[2], 3) )
           )
        ->FromPostalCode( $from->postal_code )
        ->ToPostalCode( $postal_code )
        ->ToCountryCode( $to->country )
      );

    return $b->string(true);
  }


  /**
   * Parses the response Endicia servers send and builds out a list of quotes.
   *
   * @return array quote data
   */
  public function buildQuotes(){
    if ($this->response === null || $this->sxml === null)
      throw new \LogicException('buildQuote requires a parsed quote response before quotes can be assembled');

    $this->rates = array();
    foreach ($this->sxml->PostagePrice as $rate_response) {
      $this->rates[] = array(
        'MailClass'   => (string) $rate_response->MailClass,
        'MailService' => (string) $rate_response->Postage->MailService,
        'Postage'     => (float)  $rate_response['TotalAmount'],
      );
    }

  }
}
