#!/usr/bin/perl


use LoxBerry::System;
use LoxBerry::Web;

use CGI qw/:standard/;
use Config::Simple qw/-strict/;;
use warnings;
use strict;

#define vavigation
our %navbar;
$navbar{1}{Name} = "Settings";
$navbar{1}{URL} = 'config.cgi';
$navbar{1}{active} = 1;

$navbar{2}{Name} = "Nibe API";
$navbar{2}{URL} = 'index.cgi';


#Set header for our side
my $version = LoxBerry::System::pluginversion();
my $plugintitle = "Nibe Uplink Bridge";
LoxBerry::Web::lbheader("$plugintitle V$version", "http://www.loxwiki.eu/display/LOXBERRY/Any+Plugin", "help.html");


#Load Template and fill with given parameters
my $template = HTML::Template->new(filename => "$lbptemplatedir/config.html");
my $pcfg = new Config::Simple("$lbpconfigdir/nibe.cfg");
$template->param("nibe_api_client_id" => $pcfg->param('Section.nibe_api_client_id'));
$template->param("nibe_api_client_secret" => $pcfg->param('Section.nibe_api_client_secret'));
$template->param("redirect_url" => $pcfg->param('Section.redirect_url'));

# Write template
print $template->output();

# set footer for our side
LoxBerry::Web::lbfooter();

exit;
