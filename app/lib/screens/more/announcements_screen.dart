import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key});

  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  final _api = ApiClient();
  final _dateFmt = DateFormat('MMM dd, yyyy • hh:mm a');
  bool _loading = true;
  String? _error;
  List<_Announcement> _items = [];

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
      final data = await _api.get('announcements.php');
      final list = (data['announcements'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _Announcement.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      if (!mounted) return;
      setState(() {
        _items = list;
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

  Future<void> _send() async {
    final user = AuthScope.of(context).user;
    if (user == null || !user.canManageUsers) return;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => _SendAnnouncementDialog(createdByName: user.fullName),
    );
    if (saved == true) await _load();
  }

  Future<void> _delete(_Announcement item) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete announcement?'),
        content: Text(item.title),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await _api.post('announcements.php', body: {'action': 'delete', 'id': item.id});
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    final canManage = user?.canManageUsers == true;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Announcements'),
        actions: [
          IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      floatingActionButton: canManage
          ? FloatingActionButton.extended(
              onPressed: _send,
              icon: const Icon(Icons.campaign_outlined),
              label: const Text('Send'),
            )
          : null,
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
                        const SizedBox(height: 16),
                        FilledButton(onPressed: _load, child: const Text('Retry')),
                      ],
                    ),
                  ),
                )
              : _items.isEmpty
                  ? const Center(child: Text('No announcements yet'))
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: EdgeInsets.fromLTRB(16, 16, 16, canManage ? 96 : 24),
                        itemCount: _items.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (context, i) {
                          final item = _items[i];
                          return Material(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(12),
                            child: ListTile(
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                                side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
                              ),
                              contentPadding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
                              title: Text(
                                item.title,
                                style: const TextStyle(fontWeight: FontWeight.w700),
                              ),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const SizedBox(height: 6),
                                  Text(item.message, style: const TextStyle(height: 1.35)),
                                  const SizedBox(height: 8),
                                  Text(
                                    '${item.createdByName.isEmpty ? 'Admin' : item.createdByName}'
                                    ' · ${_dateFmt.format(item.createdAt)}',
                                    style: const TextStyle(fontSize: 12, color: EcColors.muted),
                                  ),
                                ],
                              ),
                              trailing: canManage
                                  ? IconButton(
                                      onPressed: () => _delete(item),
                                      icon: const Icon(Icons.delete_outline, color: EcColors.danger),
                                    )
                                  : null,
                              isThreeLine: true,
                            ),
                          );
                        },
                      ),
                    ),
    );
  }
}

class _Announcement {
  _Announcement({
    required this.id,
    required this.title,
    required this.message,
    required this.createdByName,
    required this.createdAt,
  });

  final int id;
  final String title;
  final String message;
  final String createdByName;
  final DateTime createdAt;

  factory _Announcement.fromJson(Map<String, dynamic> json) {
    DateTime parseDate(dynamic v) {
      if (v == null) return DateTime.now();
      try {
        return DateTime.parse('$v');
      } catch (_) {
        return DateTime.now();
      }
    }

    return _Announcement(
      id: (json['id'] as num?)?.toInt() ?? 0,
      title: '${json['title'] ?? ''}',
      message: '${json['message'] ?? ''}',
      createdByName: '${json['created_by_name'] ?? ''}',
      createdAt: parseDate(json['created_at']),
    );
  }
}

class _SendAnnouncementDialog extends StatefulWidget {
  const _SendAnnouncementDialog({required this.createdByName});
  final String createdByName;

  @override
  State<_SendAnnouncementDialog> createState() => _SendAnnouncementDialogState();
}

class _SendAnnouncementDialogState extends State<_SendAnnouncementDialog> {
  final _api = ApiClient();
  final _titleCtrl = TextEditingController();
  final _messageCtrl = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _titleCtrl.dispose();
    _messageCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final title = _titleCtrl.text.trim();
    final message = _messageCtrl.text.trim();
    if (title.isEmpty || message.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Title and message are required')),
      );
      return;
    }

    setState(() => _busy = true);
    try {
      await _api.post('announcements.php', body: {
        'action': 'send',
        'title': title,
        'message': message,
        'created_by_name': widget.createdByName.isEmpty ? 'Admin' : widget.createdByName,
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Send announcement'),
      content: SingleChildScrollView(
        child: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: _titleCtrl,
                enabled: !_busy,
                decoration: const InputDecoration(labelText: 'Title'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _messageCtrl,
                enabled: !_busy,
                maxLines: 4,
                decoration: const InputDecoration(labelText: 'Message'),
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Send'),
        ),
      ],
    );
  }
}
