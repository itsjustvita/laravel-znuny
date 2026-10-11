# Znuny configuration for the laravel-znuny integration suite.
package Kernel::Config;

use strict;
use warnings;
use utf8;

sub Load {
    my $Self = shift;

    $Self->{DatabaseHost} = 'db';
    $Self->{Database}     = 'znuny';
    $Self->{DatabaseUser} = 'znuny';
    $Self->{DatabasePw}   = 'znuny';
    $Self->{DatabaseDSN}  = "DBI:mysql:database=$Self->{Database};host=$Self->{DatabaseHost};";

    # The image installs Znuny to /opt/otrs; Znuny 7 defaults to /opt/znuny.
    $Self->{Home} = '/opt/otrs';

    $Self->{FQDN}       = 'localhost';
    $Self->{SystemID}   = '10';
    $Self->{AdminEmail} = 'admin@example.com';
    $Self->{SecureMode} = 1;

    # Test data uses example.com addresses, which Znuny rejects by default.
    $Self->{CheckEmailAddresses} = 0;

    $Self->{'LogModule'}          = 'Kernel::System::Log::File';
    $Self->{'LogModule::LogFile'} = '/opt/otrs/var/log/znuny.log';

    return 1;
}

use Kernel::Config::Defaults;    # import Translatable()
use parent qw(Kernel::Config::Defaults);

1;
