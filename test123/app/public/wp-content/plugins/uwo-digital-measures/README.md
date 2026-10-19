# Digital Measures Wordpress plugin

This plugin allows higher educational institutions who use Digital Measures' Activity Insight to pull data using the web API (Web Services).
Then use that to populate faculty publication citations, educational acheivements (degrees), and presentation citations, in MLA and APA citation standards using shortcodes.

## License

General Public License version 2.

## Setup

Install files to the `/wp-content/plugins/digitalmeasures/` directory.

Add the following to your `wp-config.php`, or copy or move config.inc.php to `wp-content/digitalmeasures/config.inc.php`.
Then edit that file to reflect your Digital Measures' Web Services Account credentials.

<code><pre>
$dm_configs = array
(
'default' => array
(
'username' => '<b>digital measures webservices account username goes here</b>',
'password' => '<b>password goes here</b>',
'key' => '<b>University key</b>'
)
);

</pre></code>

Enable the plugin in the plugin page of the admin section.

## Shortcode for Publications

<code>[digitalmeasures username="netid" published_only="true" profile_only="false" limit="5" instance="default" format="apa"]</code>

- _username_ should be the username in Digital Measures for the faculty member.
- _type_ is optional. The default is "publication".
- _published_only_ is optional. The default is "no", and will limit publications listed to those with a status of published.
- _profile_only_ is optional. The default is "yes", and will limit publications listed to those where "show on faculty profile page" in Digital Measures is set to "yes".
- _limit_ is optional. The default is "0" which lists all matching publications, otherwise this is the maximum number of publications to list.
- _instance_ is optional. This will choose which instance of Digital Meausres to pull from. These are setup in the config.inc.php file.
- _format_ is optional. This decides which citation standard to use. Options are "mla" for MLA style or "apa" for APA style. The default style is MLA.
- _authors_ is optional. If present this will limit the authors in the following way:
  - If the number of authors on the publication is less than this value, all authors are shown.
  - If the number of authors on the publication is one more than all authors, all authors are shown.
  - If the number of authors on the publication is two or more larger than authors, this number of authors will be shown followed by "and others" in MLA or "et. al" in APA.
- _cache_ is optional. If supplied, it will override the "Cache Length" value entered in on the Digital Measures Settings page for the data retrieved by this instance of the shortcode.
- _unpublished_text_ is optional. If supplied, it will override the "Alternate Text for Unpublished Works" value entered in on the Digital Measures Settings page for the data retrieved by this instance of the shortcode.

## Shortcode for Education

<code>[digitalmeasures type="education" username="netid" show_location="true" show_dates="range"]</code>

<code>[digitalmeasures type="education" username="netid" show_dates="true"]</code>

- _username_ should be the username in Digital Measures for the faculty member.
- _type_ should be "education" to show the education of a faculty member.
- _show_location_ is optional. This controls showing or not showing the locations (city and state) of the university the degree was earned at. The default is "false".
- _show_dates_ is optional. This controls if and how the dates of the degree are shown. The default is "false".
  - _true_ shows the completion date only.
  - _range_ shows the start and end dates for the degrees.
  - _false_ doesn't show the dates at all.

## Shortcode for Conference Presentations

<code>[digitalmeasures type="conference_presentations" username="netid" profile_only="false" limit="5" instance="default" format="apa"]</code>

- _username_ should be the username in Digital Measures for the faculty member.
- _type_ should be "presentations" to show presentations.
- _profile_only_ is optional. The default is "yes", and will limit presentations listed to those where "show on faculty profile page" in Digital Measures is set to "yes".
- _limit_ is optional. The default is "0" which lists all matching presentations, otherwise this is the maximum number of presentations to list.
- _instance_ is optional. This will choose which instance of Digital Measures to pull from. These are setup in the config.inc.php file.
- _format_ is optional. This decides which citation standard to use. Options are "mla" for MLA style or "apa" for APA style, default is MLA.
- _authors_ is optional. If present this will limit the authors in the following way:
  - If the number of authors on the presentation is less than this value, all authors are shown.
  - If the number of authors on the presentation is one more than all authors, all authors are shown.
  - If the number of authors on the presentation is two or more larger than authors, this number of authors will be shown followed by "and others" in MLA or "et. al" in APA.

## Shortcode for Professional Presentations

<code>[digitalmeasures type="professional_presentations" username="netid" profile_only="false" limit="5" instance="default" format="apa"]</code>

- _username_ should be the username in Digital Measures for the faculty member.
- _type_ should be "presentations" to show presentations.
- _profile_only_ is optional. The default is "yes", and will limit presentations listed to those where "show on faculty profile page" in Digital Measures is set to "yes".
- _limit_ is optional. The default is "0" which lists all matching presentations, otherwise this is the maximum number of presentations to list.
- _instance_ is optional. This will choose which instance of Digital Measures to pull from. These are setup in the config.inc.php file.
- _format_ is optional. This decides which citation standard to use. Options are "mla" for MLA style or "apa" for APA style, default is MLA.
- _authors_ is optional. If present this will limit the authors in the following way:
  - If the number of authors on the presentation is less than this value, all authors are shown.
  - If the number of authors on the presentation is one more than all authors, all authors are shown.
  - If the number of authors on the presentation is two or more larger than authors, this number of authors will be shown followed by "and others" in MLA or "et. al" in APA.

## Shortcode for Awards and Honors

<code>[digitalmeasures type="awards" username="netid"]</code>

- _username_ should be the username in Digital Measures for the faculty member.
- _type_ should be "awards" to show awards.
- _limit_ is optional. The default is "0" which lists all awards, otherwise this is the maximum number of awards to list.

## Shortcode for Contracts and Grants

<code>[digitalmeasures type="grants" username="netid"]</code>

- _username_ should be the username in Digital Measures for the faculty member.
- _type_ should be "grants" to show grants.
- _limit_ is optional. The default is "0" which lists all grants, otherwise this is the maximum number of grants to list.
