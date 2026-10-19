<?php
/**
 * @file conference_presentation.inc.php
 * This file contains the presentation class
 * @author Joseph Kerkhof
 **/

class ProfessionalPresentation
{
  private $authors;
  private $title;
  private $organization;
  public $month;
  public $year;
  public $profile;

  /**
   * Constructor for the professional presentation class.
   * @param array $authors the array of contributors who gave the presentation.
   * @param string $title the title of the presentation
   * @param string $organization the organization the presentation was held.
   * @param string $month the month the presentation was given.
   * @param string $year the year the presentation was given. 
   * @param bool $profile Weather or not to show this on the profile page.
   **/
  public function __construct($authors,$title,$organization,$month,$year,$profile)
  {
    $this->authors = $authors;
    $this->title = trim($title);
    $this->organization = trim($organization);
    $this->month = trim($month);
    $this->year = trim($year);
    $this->profile = trim($profile);
  }

  /**
   * Out puts this presentation's citation in MLA format in either text or HTML.
   * @param string $type the type of output, either 'html' or 'text'. Option, default is 'text'.
   * @param int $authors the number of authors to show.
   * @return the mla citation of this presentation.
   **/
  public function construct_citation($type = 'text',$authors = null)
  {
    $cite = professional_presentation_contribs($this->authors,$type,$authors);
    if('html' == $type)
    {
      $cite = htmlentities($cite);
    }

    // title
    if('html' == $type)
    {
      $cite .= htmlentities(str_replace('"',"'",$this->title));
    }
    else
    {
      $cite .= str_replace('"',"'",$this->title);
    }

    $cite .= ', ';

    // organization
    if('html' == $type)
    {
      $cite .= htmlentities($this->organization);
    }
    else
    {
      $cite .= $this->organization;
    }
    $cite .= '. ';

    // date
    $cite .= ($this->month != '' ? $this->month . ' ' : '') . $this->year . '.';

    return $cite;
  }

  /**
   * Returns weather or not the publication should be listed in the profile.
   * @return true if the Faculty has agreed to list publication on profile page, false otherwise.
   **/
  function in_profile()
  {
    return (true == $this->profile);
  }

}

?>