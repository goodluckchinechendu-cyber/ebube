import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:share_plus/share_plus.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';

class InviteLinkScreen extends StatefulWidget {
  const InviteLinkScreen({super.key});

  @override
  State<InviteLinkScreen> createState() => _InviteLinkScreenState();
}

class _InviteLinkScreenState extends State<InviteLinkScreen> {
  final _api = ApiClient();
  bool _loading = true;
  String? _error;
  String? _url;
  String? _code;
  int _underCount = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await _api.get('invite_link.php');
      if (!mounted) return;
      setState(() {
        _url = '${data['invite_url'] ?? ''}';
        _code = '${data['referral_code'] ?? ''}';
        _underCount = (data['registered_under_count'] as num?)?.toInt() ?? 0;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = '$e';
        _loading = false;
      });
    }
  }

  Future<void> _copy() async {
    final url = _url;
    if (url == null || url.isEmpty) return;
    await Clipboard.setData(ClipboardData(text: url));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Invite link copied')),
    );
  }

  Future<void> _share() async {
    final url = _url;
    if (url == null || url.isEmpty) return;
    try {
      await SharePlus.instance.share(
        ShareParams(
          text: 'Join me on EbubeConnect:\n$url',
          subject: 'EbubeConnect invite',
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Invite Link'),
        actions: [
          IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(_error!, textAlign: TextAlign.center),
                        const SizedBox(height: 12),
                        FilledButton(onPressed: _load, child: const Text('Retry')),
                      ],
                    ),
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.all(20),
                  children: [
                    const Text(
                      'Share this link. Anyone who opens it and registers will be under your account.',
                      style: TextStyle(color: EcColors.muted, height: 1.4),
                    ),
                    const SizedBox(height: 20),
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: Colors.black.withValues(alpha: 0.06)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Your invite code', style: TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(height: 6),
                          SelectableText(
                            _code ?? '',
                            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: 1.2),
                          ),
                          const SizedBox(height: 16),
                          const Text('Invite link', style: TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(height: 6),
                          SelectableText(
                            _url ?? '',
                            style: const TextStyle(fontSize: 13, height: 1.35),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            'Registered under you: ${NumberFormat.decimalPattern().format(_underCount)}',
                            style: const TextStyle(color: EcColors.muted, fontSize: 13),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),
                    FilledButton.icon(
                      onPressed: _share,
                      icon: const Icon(Icons.share_rounded),
                      label: const Text('Share invite link'),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: _copy,
                      icon: const Icon(Icons.copy_rounded),
                      label: const Text('Copy link'),
                    ),
                  ],
                ),
    );
  }
}
