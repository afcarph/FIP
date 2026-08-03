import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/network/api_exception.dart';
import '../../../shared/providers/app_providers.dart';

class _Turn {
  const _Turn({required this.role, required this.content, this.sources = const []});

  final String role;
  final String content;
  final List<String> sources;
}

/// The conversational advisor.
///
/// Answers name their sources, which is what makes them checkable — an
/// assistant that confidently invents a price is worse than none at all.
class AssistantScreen extends ConsumerStatefulWidget {
  const AssistantScreen({super.key});

  @override
  ConsumerState<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends ConsumerState<AssistantScreen> {
  final _controller = TextEditingController();
  final _scrollController = ScrollController();

  final List<_Turn> _turns = [];
  List<String> _suggestions = const [
    'Should I refuel today?',
    'Where is the cheapest diesel near me?',
    'Why did my fuel consumption increase?',
    'How much did I spend on fuel last month?',
  ];

  int? _sessionId;
  bool _sending = false;

  @override
  void dispose() {
    _controller.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _send(String question) async {
    final trimmed = question.trim();
    if (trimmed.isEmpty || _sending) return;

    setState(() {
      _turns.add(_Turn(role: 'user', content: trimmed));
      _sending = true;
      _controller.clear();
    });

    _scrollToEnd();

    try {
      final location = await ref.read(locationProvider.future);

      final reply = await ref
          .read(apiClientProvider)
          .post<Map<String, dynamic>>(
            '/assistant/chat',
            body: {
              'question': trimmed,
              if (_sessionId != null) 'session_id': _sessionId,
              'latitude': location.latitude,
              'longitude': location.longitude,
            },
          );

      setState(() {
        _sessionId = reply['session_id'] as int?;
        _suggestions = (reply['suggestions'] as List<dynamic>? ?? []).cast<String>();
        _turns.add(
          _Turn(
            role: 'assistant',
            content: reply['answer'] as String? ?? 'I could not work that one out.',
            sources: (reply['sources'] as List<dynamic>? ?? []).cast<String>(),
          ),
        );
      });
    } on ApiException catch (error) {
      setState(() => _turns.add(_Turn(role: 'assistant', content: error.message)));
    } finally {
      if (mounted) setState(() => _sending = false);
      _scrollToEnd();
    }
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;

      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('AI Advisor')),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child:
                  _turns.isEmpty
                      ? _EmptyConversation(onPick: _send, suggestions: _suggestions)
                      : ListView.builder(
                        controller: _scrollController,
                        padding: const EdgeInsets.all(16),
                        itemCount: _turns.length + (_sending ? 1 : 0),
                        itemBuilder: (context, index) {
                          if (index >= _turns.length) return const _TypingBubble();

                          return _MessageBubble(turn: _turns[index]);
                        },
                      ),
            ),
            if (_turns.isNotEmpty && _suggestions.isNotEmpty && !_sending)
              SizedBox(
                height: 44,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  children: [
                    for (final suggestion in _suggestions.take(4))
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                        child: ActionChip(
                          label: Text(suggestion, style: const TextStyle(fontSize: 12)),
                          onPressed: () => _send(suggestion),
                        ),
                      ),
                  ],
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _controller,
                      textInputAction: TextInputAction.send,
                      onSubmitted: _send,
                      enabled: !_sending,
                      decoration: const InputDecoration(
                        hintText: 'Ask about prices, savings or your vehicles…',
                        isDense: true,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    onPressed: _sending ? null : () => _send(_controller.text),
                    icon: const Icon(LucideIcons.send, size: 18),
                    tooltip: 'Send',
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.turn});

  final _Turn turn;

  @override
  Widget build(BuildContext context) {
    final isUser = turn.role == 'user';
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        mainAxisAlignment: isUser ? MainAxisAlignment.end : MainAxisAlignment.start,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (!isUser) ...[
            CircleAvatar(
              radius: 15,
              backgroundColor: scheme.primary.withValues(alpha: 0.12),
              child: Icon(LucideIcons.sparkles, size: 15, color: scheme.primary),
            ),
            const SizedBox(width: 10),
          ],
          Flexible(
            child: Column(
              crossAxisAlignment: isUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: isUser ? scheme.primary : scheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    turn.content,
                    style: TextStyle(
                      color: isUser ? scheme.onPrimary : scheme.onSurface,
                      height: 1.4,
                    ),
                  ),
                ),
                if (turn.sources.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Based on: ${turn.sources.join(' · ')}',
                    style: Theme.of(
                      context,
                    ).textTheme.labelSmall?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TypingBubble extends StatelessWidget {
  const _TypingBubble();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        children: [
          CircleAvatar(
            radius: 15,
            backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
            child: Icon(
              LucideIcons.sparkles,
              size: 15,
              color: Theme.of(context).colorScheme.primary,
            ),
          ),
          const SizedBox(width: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(16),
            ),
            child: const SizedBox(
              width: 20,
              height: 8,
              child: Center(child: LinearProgressIndicator(minHeight: 3)),
            ),
          ),
        ],
      ),
    );
  }
}

class _EmptyConversation extends StatelessWidget {
  const _EmptyConversation({required this.onPick, required this.suggestions});

  final ValueChanged<String> onPick;
  final List<String> suggestions;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(28),
      child: Column(
        children: [
          const SizedBox(height: 40),
          CircleAvatar(
            radius: 30,
            backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
            child: Icon(
              LucideIcons.sparkles,
              size: 28,
              color: Theme.of(context).colorScheme.primary,
            ),
          ),
          const SizedBox(height: 18),
          Text(
            'Ask me anything about fuel',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 6),
          Text(
            'I work from your own data — your vehicles, your fill-ups and live prices around you.',
            textAlign: TextAlign.center,
            style: Theme.of(
              context,
            ).textTheme.bodyMedium?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
          ),
          const SizedBox(height: 28),
          for (final suggestion in suggestions)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: SizedBox(
                width: double.infinity,
                child: OutlinedButton(
                  onPressed: () => onPick(suggestion),
                  child: Align(alignment: Alignment.centerLeft, child: Text(suggestion)),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
