import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/app_colors.dart';
import '../core/motion.dart';

/// ============================================================
/// QUEUEFLOW TMC — PWD PROOF PICKER
///
/// The photo a student attaches when they claim the Person with Disability
/// priority. The claim is only a claim until staff can see the evidence, so
/// this control sits directly under the priority choice and the ticket form
/// will not submit while it is empty.
///
/// Three states, one per line of the spec:
///
///   - nothing picked  -> "Select Image from Gallery", opening the device's
///                        photo picker. The camera is never opened; the ask is
///                        for an image the student already has.
///   - something picked -> a preview card with Change Image / Remove Image,
///                        so the student sees exactly what will be uploaded.
///   - picking          -> a busy indicator, so a slow gallery cannot be
///                        double-tapped into two picks.
///
/// Cancelling the gallery returns no file. That is not an error and is not
/// reported as one: the button stays, the form stays, and the student can try
/// again. A genuine failure (permission revoked, picker unavailable) is
/// reported through [onError] so the screen can show its own message.
///
/// The pick is compressed before it leaves the device (quality 80, longest
/// edge 1600px) because the Laravel side refuses evidence over 2 MB, and a
/// rejected upload after a slow submit is a worse answer than a smaller photo.
///
/// [pickImage] exists so tests can drive the picker without a platform
/// channel; production code never passes it and always opens the gallery.
/// ============================================================

/// Opens the picker and returns the chosen file path, or null on cancel.
typedef ProofImagePicker = Future<String?> Function();

class PwdProofPicker extends StatefulWidget {
  const PwdProofPicker({
    super.key,
    required this.imagePath,
    required this.onChanged,
    this.onError,
    this.pickImage,
  });

  /// The currently selected proof image, or null when there is none.
  final String? imagePath;

  /// Fires with the new path on pick/change, or null on remove.
  final ValueChanged<String?> onChanged;

  /// Fires when the picker itself failed, for the screen to surface.
  final ValueChanged<String>? onError;

  /// Test seam. Null means "open the device gallery".
  final ProofImagePicker? pickImage;

  @override
  State<PwdProofPicker> createState() => _PwdProofPickerState();
}

class _PwdProofPickerState extends State<PwdProofPicker> {
  bool _busy = false;

  Future<void> _pick() async {
    if (_busy) {
      return;
    }

    setState(() => _busy = true);

    try {
      final String? path = await (widget.pickImage ?? _pickFromGallery)();

      if (!mounted) {
        return;
      }

      setState(() => _busy = false);

      // null is a cancelled gallery: nothing selected, nothing wrong.
      if (path != null && path.isNotEmpty) {
        widget.onChanged(path);
      }
    } catch (_) {
      if (!mounted) {
        return;
      }

      setState(() => _busy = false);
      widget.onError?.call(
        'Could not open the gallery. Check photo permissions and try again.',
      );
    }
  }

  void _remove() => widget.onChanged(null);

  @override
  Widget build(BuildContext context) {
    final String? path = widget.imagePath;

    if (path == null || path.isEmpty) {
      return _galleryButton();
    }

    return _preview(path);
  }

  /// The empty state: one clear way to give the proof.
  Widget _galleryButton() {
    return PressScale(
      pressedScale: 0.985,
      onTap: _busy ? null : _pick,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
        ),
        child: _busy
            ? const Row(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: <Widget>[
                  SizedBox(
                    width: 15,
                    height: 15,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: AppColors.sky,
                    ),
                  ),
                  SizedBox(width: 10),
                  Text(
                    'Opening gallery…',
                    style: TextStyle(
                      color: Colors.white70,
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              )
            : const Row(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: <Widget>[
                  Icon(
                    Icons.photo_library_outlined,
                    size: 18,
                    color: AppColors.sky,
                  ),
                  SizedBox(width: 10),
                  Flexible(
                    child: Text(
                      'Select Image from Gallery',
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  /// The selected state: what will be uploaded, and the two ways to change it.
  Widget _preview(String path) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white.withValues(alpha: 0.10)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: AspectRatio(
              aspectRatio: 16 / 10,
              child: Image(
                image: _imageFor(path),
                fit: BoxFit.cover,
                // A file this build cannot decode must not take the form down
                // with it. The preview degrades to a placeholder and the
                // upload still sends the original bytes, which is what the
                // server actually validates.
                errorBuilder: (BuildContext context, Object error,
                        StackTrace? stack) =>
                    Container(
                  color: Colors.white.withValues(alpha: 0.05),
                  alignment: Alignment.center,
                  child: const Icon(
                    Icons.image_outlined,
                    size: 36,
                    color: Colors.white38,
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: <Widget>[
              const Icon(
                Icons.check_circle_rounded,
                size: 14,
                color: AppColors.success,
              ),
              const SizedBox(width: 7),
              Text(
                'Selected image',
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.55),
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: <Widget>[
              Expanded(
                child: _ActionButton(
                  label: 'Change Image',
                  icon: Icons.swap_horiz_rounded,
                  onTap: _busy ? null : _pick,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _ActionButton(
                  label: 'Remove Image',
                  icon: Icons.delete_outline_rounded,
                  danger: true,
                  onTap: _remove,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Local paths decode straight off disk; the web picker hands back a
  /// blob:/http: URL, which only a network image can load.
  ImageProvider _imageFor(String path) {
    final Uri? uri = Uri.tryParse(path);

    if (uri != null &&
        (uri.scheme == 'http' ||
            uri.scheme == 'https' ||
            uri.scheme == 'blob' ||
            uri.scheme == 'data')) {
      return NetworkImage(path);
    }

    return FileImage(File(path));
  }

  /// The gallery, compressed to fit under the server's 2 MB ceiling.
  static Future<String?> _pickFromGallery() async {
    final XFile? picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
      maxWidth: 1600,
      maxHeight: 1600,
    );

    return picked?.path;
  }
}

/// One of the two controls under the preview.
class _ActionButton extends StatelessWidget {
  const _ActionButton({
    required this.label,
    required this.icon,
    required this.onTap,
    this.danger = false,
  });

  final String label;
  final IconData icon;
  final VoidCallback? onTap;

  /// Remove reads as a destructive control, so it takes the danger tint
  /// rather than looking equal to Change.
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final Color tint = danger ? AppColors.danger : AppColors.sky;

    return PressScale(
      pressedScale: 0.97,
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          color: danger
              ? AppColors.danger.withValues(alpha: 0.10)
              : Colors.white.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: danger
                ? AppColors.danger.withValues(alpha: 0.35)
                : Colors.white.withValues(alpha: 0.14),
          ),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(icon, size: 15, color: tint),
            const SizedBox(width: 7),
            Flexible(
              child: Text(
                label,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: danger
                      ? AppColors.danger
                      : Colors.white.withValues(alpha: 0.85),
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
