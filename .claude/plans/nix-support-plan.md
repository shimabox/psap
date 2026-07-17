# Nix Support Implementation Plan

## Overview
Add Nix flake support to psap to enable:
1. Reproducible development environments
2. Hermetic builds
3. Easy installation via `nix run` / `nix shell`
4. Integration with NixOS-based CI/CD

## Goals
- Create a `flake.nix` for development environment and distribution
- Maintain backward compatibility with existing Docker/Makefile workflows
- Enable `nix flake show` to display available outputs
- Support both ephemeral dev shells and persistent installations

## Phase 1: Development Environment (flake.nix)

### Deliverables
1. **flake.nix** with:
   - `devShells.default`: PHP 8.5+, Composer, Make, git (development)
   - `packages.default`: Built psap CLI binary/script
   - `apps.default`: Runnable psap command via `nix run`

2. **flake.lock**: Version pinning for reproducibility

### Technical Details
- Pin nixpkgs to a stable version
- Use PHP 8.5 or later (per README requirement)
- Include build inputs: php, composer, make
- Include dev inputs: phpunit, phpstan, php-cs-fixer (from composer.json)
- Copy bin/psap as executable entry point

### Verification
```bash
nix flake show                    # Lists available outputs
nix run . -- --version            # Verify CLI works
nix shell . -- composer install   # Verify dev environment
```

## Phase 2: Distribution Package (default.nix)

### Deliverables
1. **default.nix**: Alternative to flake.nix for:
   - Non-flake Nix installations
   - Simpler maintenance in systems without flake support
   - Packaging for nixpkgs submission (future)

### Technical Details
- Mirror flake.nix package definition
- No external dependencies beyond standard nixpkgs
- Suitable for `nix-build` and traditional nix workflows

### Verification
```bash
nix-build                         # Build with default.nix
./result/bin/psap --version       # Test output
```

## Phase 3: Documentation & Integration

### Deliverables
1. **docs/nix.md**: Usage guide covering:
   - Installation via nix flake / nix-env
   - Dev environment setup (`nix develop`)
   - Running analysis with Nix
   - Troubleshooting

2. **README.md update**: Add Nix installation method alongside Docker

3. **CI integration** (optional Phase 2):
   - Add Nix build check to `.github/workflows/ci.yml`
   - Test `nix build` and `nix run` in CI

## Phase 4: Docker -> Nix Bridge (Optional)

### Considerations
- Multi-stage Docker build could use Nix-built artifacts
- Or deprecate Docker in favor of Nix for NixOS users
- Keep Docker for non-Nix Linux/macOS users

## Architecture Decisions

### 1. Single flake.nix vs. flake.nix + default.nix
**Decision**: Include both
- **flake.nix**: Primary (modern, recommended)
- **default.nix**: Fallback (simplicity, compatibility)

### 2. Build approach
**Decision**: Package the compiled PHP artifacts + runtime
- Copy vendor/ + src/ into closure
- bin/psap as executable wrapper
- Runtime dependency on PHP interpreter only

### 3. Versioning
**Decision**: Track git tags
- Use `git describe` or parse from composer.json version
- Sync with GitHub releases

## Known Constraints
1. **PHP build**: nixpkgs PHP is pre-built; no custom extensions needed for psap
2. **Composer dependencies**: Resolved at build time; locked in flake.lock
3. **Entry point**: bin/psap expects PHP on PATH (provide via wrapper)
4. **Size**: Nix closures include all transitive deps; acceptable for CLI tool

## Testing Strategy
1. `nix flake check` (schema validation)
2. `nix build` (full build test)
3. `nix run . -- --version` (smoke test)
4. `nix develop` + `composer test` (dev env test)
5. Cross-platform testing (Linux, macOS via nix-darwin)

## Success Criteria
- [ ] `nix run` produces working psap CLI
- [ ] `nix develop` provides full dev environment
- [ ] No Nix knowledge required to use (`nix run . -- analyze src/`)
- [ ] flake.lock pinned and reproducible across machines
- [ ] Documentation clear and discoverable in README

## Timeline
- **Phase 1**: 2-3 hours (flake.nix structure)
- **Phase 2**: 1-2 hours (default.nix adaptation)
- **Phase 3**: 1-2 hours (docs + README)
- **Phase 4** (optional): 1-2 hours (CI integration)

**Total**: ~5-9 hours for full implementation

## Rollout
1. Branch: `claude/nix-support-plan-rnk5wg`
2. PR: Describe Nix integration, link docs
3. Merge once tested and docs reviewed
4. Announce in README and release notes if applicable
